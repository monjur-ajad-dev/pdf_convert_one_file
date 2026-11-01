<?php
/**
 * merge.php — receives many files (in order) and returns ONE merged PDF.
 * No database. Everything happens in a temporary folder that is deleted afterwards.
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
@ini_set('display_errors', '0');

const IMAGE_EXT  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
const OFFICE_EXT = ['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp'];
const PHPWORD_EXT = ['docx', 'odt', 'rtf'];

function fail(string $msg, int $code = 422): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Find an external program (LibreOffice / Ghostscript) on this Windows/Linux machine. */
function find_binary(array $names, array $extraPaths = []): ?string
{
    foreach ($extraPaths as $p) {
        foreach (glob($p) ?: [] as $hit) {
            if (is_file($hit)) return $hit;
        }
    }
    $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
    foreach ($names as $n) {
        $out = [];
        @exec($which . ' ' . escapeshellarg($n) . ' 2>&1', $out, $rc);
        if ($rc === 0 && !empty($out[0]) && is_file(trim($out[0]))) return trim($out[0]);
    }
    return null;
}

function libreoffice(): ?string
{
    static $bin = false;
    if ($bin === false) {
        $bin = find_binary(['soffice', 'soffice.exe', 'libreoffice'], [
            'C:\\Program Files\\LibreOffice*\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice*\\program\\soffice.exe',
        ]);
    }
    return $bin;
}

function ghostscript(): ?string
{
    static $bin = false;
    if ($bin === false) {
        $bin = find_binary(['gswin64c', 'gswin32c', 'gs'], [
            'C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe',
            'C:\\Program Files (x86)\\gs\\gs*\\bin\\gswin32c.exe',
        ]);
    }
    return $bin;
}

/** Convert Word/Excel/PowerPoint etc. to PDF using LibreOffice (best quality). */
function office_to_pdf_libreoffice(string $src, string $workDir): ?string
{
    $soffice = libreoffice();
    if (!$soffice) return null;
    $outDir  = $workDir . DIRECTORY_SEPARATOR . 'lo_' . bin2hex(random_bytes(4));
    $profile = $workDir . DIRECTORY_SEPARATOR . 'lo_profile';
    mkdir($outDir, 0777, true);
    $profileUrl = 'file:///' . str_replace('\\', '/', ltrim($profile, '/\\'));
    $cmd = escapeshellarg($soffice)
        . ' -env:UserInstallation=' . escapeshellarg($profileUrl)
        . ' --headless --norestore --convert-to pdf --outdir ' . escapeshellarg($outDir)
        . ' ' . escapeshellarg($src) . ' 2>&1';
    @exec($cmd);
    $pdf = $outDir . DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_FILENAME) . '.pdf';
    return is_file($pdf) ? $pdf : null;
}

/** Fallback: DOCX/ODT/RTF -> PDF with PhpWord + Dompdf (basic formatting only). */
function office_to_pdf_phpword(string $src, string $workDir): ?string
{
    try {
        \PhpOffice\PhpWord\Settings::setPdfRendererName(\PhpOffice\PhpWord\Settings::PDF_RENDERER_DOMPDF);
        \PhpOffice\PhpWord\Settings::setPdfRendererPath(__DIR__ . '/vendor/dompdf/dompdf');
        $word = \PhpOffice\PhpWord\IOFactory::load($src);
        $out  = $workDir . DIRECTORY_SEPARATOR . 'pw_' . bin2hex(random_bytes(4)) . '.pdf';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'PDF')->save($out);
        return is_file($out) ? $out : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/** Load any raster image via GD, fix rotation, flatten transparency, save as JPEG. */
function image_to_jpeg(string $src, string $workDir): string
{
    $data = file_get_contents($src);
    $im   = @imagecreatefromstring($data);
    if (!$im) throw new RuntimeException('Cannot read image');

    // honour EXIF orientation (phone photos)
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $o = $exif['Orientation'] ?? 1;
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) $im = imagerotate($im, $rot, 0) ?: $im;
    }

    $w = imagesx($im); $h = imagesy($im);
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);

    $out = $workDir . DIRECTORY_SEPARATOR . 'img_' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($flat, $out, 92);
    return $out;
}

function add_image_page(Fpdi $pdf, string $jpg): void
{
    [$iw, $ih] = getimagesize($jpg);
    $landscape = $iw > $ih;
    $pw = $landscape ? 297 : 210;
    $ph = $landscape ? 210 : 297;
    $margin = 10;
    $pdf->AddPage($landscape ? 'L' : 'P', 'A4');

    $maxW = $pw - 2 * $margin;
    $maxH = $ph - 2 * $margin;
    $scale = min($maxW / $iw, $maxH / $ih);
    $w = $iw * $scale; $h = $ih * $scale;
    $pdf->Image($jpg, ($pw - $w) / 2, ($ph - $h) / 2, $w, $h, 'JPG');
}

function add_text_pages(Fpdi $pdf, string $txt): void
{
    $text = file_get_contents($txt);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $text = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text) ?: '';
    $pdf->AddPage('P', 'A4');
    $pdf->SetFont('Courier', '', 10);
    $pdf->SetMargins(15, 15, 15);
    $pdf->MultiCell(0, 5, str_replace("\t", '    ', $text));
}

/** Import every page of an existing PDF. */
function add_pdf_pages(Fpdi $pdf, string $file, string $workDir, string $label): void
{
    try {
        $count = $pdf->setSourceFile($file);
    } catch (\Throwable $e) {
        // FPDI free cannot read PDF 1.5+ compressed files -> try Ghostscript to rewrite as PDF 1.4
        $gs = ghostscript();
        if (!$gs) {
            throw new RuntimeException("\"$label\": this PDF uses a newer compression that can't be merged (install Ghostscript to enable it).");
        }
        $fixed = $workDir . DIRECTORY_SEPARATOR . 'gs_' . bin2hex(random_bytes(4)) . '.pdf';
        @exec(escapeshellarg($gs) . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -dCompatibilityLevel=1.4'
            . ' -sOutputFile=' . escapeshellarg($fixed) . ' ' . escapeshellarg($file) . ' 2>&1');
        if (!is_file($fixed)) throw new RuntimeException("\"$label\": could not read this PDF.");
        $count = $pdf->setSourceFile($fixed);
    }
    for ($i = 1; $i <= $count; $i++) {
        $tpl  = $pdf->importPage($i);
        $size = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
    }
}

// ----------------------------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (empty($_FILES['files'])) fail('No files received. (Files may be larger than the server upload limit.)');

$names = $_FILES['files']['name'];
$tmps  = $_FILES['files']['tmp_name'];
$errs  = $_FILES['files']['error'];
if (!is_array($names)) { $names = [$names]; $tmps = [$tmps]; $errs = [$errs]; }

$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdfmerge_' . bin2hex(random_bytes(6));
mkdir($work, 0777, true);
register_shutdown_function(fn() => rrmdir($work));

try {
    $pdf = new Fpdi('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetCompression(true);
    $pdf->SetTitle('Merged document');
    $done = 0;

    foreach ($names as $i => $orig) {
        $label = basename((string)$orig);
        if ($errs[$i] !== UPLOAD_ERR_OK) throw new RuntimeException("\"$label\": upload failed (code {$errs[$i]}).");

        $ext = strtolower(pathinfo($label, PATHINFO_EXTENSION));
        // copy to our work dir with a safe name (never trust the client's filename)
        $src = $work . DIRECTORY_SEPARATOR . 'in_' . $i . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
        if (!move_uploaded_file($tmps[$i], $src)) throw new RuntimeException("\"$label\": could not store upload.");

        if ($ext === 'pdf') {
            add_pdf_pages($pdf, $src, $work, $label);
        } elseif (in_array($ext, IMAGE_EXT, true)) {
            add_image_page($pdf, image_to_jpeg($src, $work));
        } elseif ($ext === 'txt') {
            add_text_pages($pdf, $src);
        } elseif (in_array($ext, OFFICE_EXT, true)) {
            $converted = office_to_pdf_libreoffice($src, $work);
            if (!$converted && in_array($ext, PHPWORD_EXT, true)) {
                $converted = office_to_pdf_phpword($src, $work);
            }
            if (!$converted) {
                throw new RuntimeException("\"$label\": .$ext needs LibreOffice installed on this PC to convert (https://www.libreoffice.org).");
            }
            add_pdf_pages($pdf, $converted, $work, $label);
        } else {
            throw new RuntimeException("\"$label\": file type .$ext is not supported.");
        }
        $done++;
    }

    if ($done === 0) fail('Please select at least one file.');

    $bytes = $pdf->Output('S');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: inline; filename="merged.pdf"');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
} catch (\Throwable $e) {
    fail($e->getMessage());
}
