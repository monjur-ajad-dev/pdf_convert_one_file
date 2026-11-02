<?php
/**
 * convert.php — converts ONE non-PDF, non-image file (Word/Excel/PowerPoint/ODT/RTF/TXT) to PDF.
 * The browser does the actual merging (see index.php). No database; temp files are deleted.
 */
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
@ini_set('display_errors', '0');

const OFFICE_EXT  = ['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp'];
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

/** exec() is disabled on some servers — never call it blindly. */
function can_exec(): bool
{
    return function_exists('exec');
}

/** Find LibreOffice (checks the usual install folders first, then PATH). */
function libreoffice(): ?string
{
    foreach ([
        'C:\\Program Files\\LibreOffice*\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice*\\program\\soffice.exe',
        '/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/local/bin/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
    ] as $pattern) {
        foreach (glob($pattern) ?: [] as $hit) {
            if (is_file($hit)) return $hit;
        }
    }
    if (can_exec()) {
        $out = [];
        @exec((stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which') . ' soffice 2>&1', $out, $rc);
        if ($rc === 0 && !empty($out[0]) && is_file(trim($out[0]))) return trim($out[0]);
    }
    return null;
}

function office_to_pdf_libreoffice(string $src, string $workDir): ?string
{
    $soffice = libreoffice();
    if (!$soffice || !can_exec()) return null;
    $outDir  = $workDir . DIRECTORY_SEPARATOR . 'lo_out';
    $profile = $workDir . DIRECTORY_SEPARATOR . 'lo_profile';
    mkdir($outDir, 0777, true);
    $profileUrl = 'file:///' . str_replace('\\', '/', ltrim($profile, '/\\'));
    @exec(escapeshellarg($soffice)
        . ' -env:UserInstallation=' . escapeshellarg($profileUrl)
        . ' --headless --norestore --convert-to pdf --outdir ' . escapeshellarg($outDir)
        . ' ' . escapeshellarg($src) . ' 2>&1');
    $pdf = $outDir . DIRECTORY_SEPARATOR . pathinfo($src, PATHINFO_FILENAME) . '.pdf';
    return is_file($pdf) ? $pdf : null;
}

/** Fallback without LibreOffice: DOCX/ODT/RTF -> PDF via PhpWord + Dompdf (basic formatting). */
function office_to_pdf_phpword(string $src, string $workDir): ?string
{
    try {
        \PhpOffice\PhpWord\Settings::setPdfRendererName(\PhpOffice\PhpWord\Settings::PDF_RENDERER_DOMPDF);
        \PhpOffice\PhpWord\Settings::setPdfRendererPath(__DIR__ . '/vendor/dompdf/dompdf');
        $word = \PhpOffice\PhpWord\IOFactory::load($src);
        $out  = $workDir . DIRECTORY_SEPARATOR . 'pw.pdf';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'PDF')->save($out);
        return is_file($out) ? $out : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function text_to_pdf(string $txt): string
{
    $text = file_get_contents($txt);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $text = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text) ?: '';
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();
    $pdf->SetFont('Courier', '', 10);
    $pdf->MultiCell(0, 5, str_replace("\t", '    ', $text));
    return $pdf->Output('S');
}

// ---------------------------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    fail('Upload failed. (The file may be larger than the server upload limit.)');
}

$label = basename((string)$_FILES['file']['name']);
$ext   = strtolower(pathinfo($label, PATHINFO_EXTENSION));

$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdfconv_' . bin2hex(random_bytes(6));
mkdir($work, 0777, true);
register_shutdown_function(fn() => rrmdir($work));

$src = $work . DIRECTORY_SEPARATOR . 'in.' . preg_replace('/[^a-z0-9]/', '', $ext);
if (!move_uploaded_file($_FILES['file']['tmp_name'], $src)) fail("\"$label\": could not store upload.");

try {
    if ($ext === 'txt') {
        $bytes = text_to_pdf($src);
    } elseif (in_array($ext, OFFICE_EXT, true)) {
        $pdf = office_to_pdf_libreoffice($src, $work);
        if (!$pdf && in_array($ext, PHPWORD_EXT, true)) $pdf = office_to_pdf_phpword($src, $work);
        if (!$pdf) {
            fail("\"$label\": .$ext needs LibreOffice installed on the server to convert (https://www.libreoffice.org)."
                . (can_exec() ? '' : ' Also, exec() is disabled in this PHP, so LibreOffice cannot be started.'));
        }
        $bytes = file_get_contents($pdf);
    } else {
        fail("\"$label\": file type .$ext is not supported by the converter.");
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
} catch (\Throwable $e) {
    fail("\"$label\": " . $e->getMessage());
}
