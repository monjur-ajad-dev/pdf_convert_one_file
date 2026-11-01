<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Merge to PDF</title>
<style>
  :root{
    --bg:#f5f6fa; --card:#fff; --text:#1f2430; --muted:#6b7280; --line:#e3e6ee;
    --accent:#4f46e5; --accent-d:#4338ca; --danger:#dc2626; --ok:#16a34a;
  }
  @media (prefers-color-scheme: dark){
    :root{ --bg:#12141a; --card:#1b1e27; --text:#e8eaf0; --muted:#9aa1b2; --line:#2b2f3b; --accent:#7c78ff; --accent-d:#6a66f0; }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif}
  main{max-width:760px;margin:0 auto;padding:32px 16px 64px}
  h1{font-size:26px;margin:0 0 4px}
  .sub{color:var(--muted);margin:0 0 24px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:20px}
  #drop{border:2px dashed var(--line);border-radius:12px;padding:36px 16px;text-align:center;cursor:pointer;transition:.15s}
  #drop.over,#drop:hover{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 7%,transparent)}
  #drop strong{display:block;font-size:17px;margin-bottom:4px}
  #drop span{color:var(--muted);font-size:13px}
  #list{list-style:none;margin:16px 0 0;padding:0;display:flex;flex-direction:column;gap:8px}
  .item{display:flex;align-items:center;gap:12px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:var(--card)}
  .item.dragging{opacity:.4}
  .item.dragover{border-color:var(--accent)}
  .grip{cursor:grab;color:var(--muted);user-select:none;font-size:18px;padding:0 2px}
  .thumb{width:44px;height:44px;border-radius:8px;object-fit:cover;background:var(--bg);flex:none}
  .badge{width:44px;height:44px;border-radius:8px;flex:none;display:grid;place-items:center;font-size:11px;font-weight:700;color:#fff;text-transform:uppercase}
  .meta{flex:1;min-width:0}
  .name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500}
  .size{color:var(--muted);font-size:12px}
  .btn-i{border:0;background:transparent;color:var(--muted);cursor:pointer;font-size:16px;padding:6px 8px;border-radius:8px}
  .btn-i:hover:not(:disabled){background:var(--bg);color:var(--text)}
  .btn-i:disabled{opacity:.3;cursor:default}
  .btn-i.del:hover{color:var(--danger)}
  .bar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:18px}
  .count{color:var(--muted)}
  button.primary{background:var(--accent);color:#fff;border:0;border-radius:10px;padding:11px 22px;font-size:15px;font-weight:600;cursor:pointer}
  button.primary:hover:not(:disabled){background:var(--accent-d)}
  button.primary:disabled{opacity:.5;cursor:default}
  button.link{background:none;border:0;color:var(--muted);cursor:pointer;font-size:14px;text-decoration:underline}
  #msg{margin-top:14px;padding:10px 12px;border-radius:10px;font-size:14px;display:none}
  #msg.err{display:block;background:color-mix(in srgb,var(--danger) 12%,transparent);color:var(--danger)}
  #msg.ok{display:block;background:color-mix(in srgb,var(--ok) 12%,transparent);color:var(--ok)}
  #msg a{color:inherit;font-weight:600}
  .empty{display:none}
  .options-bar{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    margin-top:16px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;
    background:color-mix(in srgb,var(--bg) 60%,var(--card));flex-wrap:wrap;
  }
  .opt-group{display:flex;align-items:center;gap:8px;font-size:13px}
  .opt-group label{font-weight:600;color:var(--text)}
  .tag-a4{
    display:inline-flex;align-items:center;gap:4px;
    background:color-mix(in srgb,var(--accent) 15%,transparent);
    color:var(--accent);font-weight:700;padding:4px 10px;border-radius:6px;font-size:12px;
    letter-spacing:0.3px;
  }
  .select-input{
    background:var(--card);color:var(--text);border:1px solid var(--line);
    padding:6px 12px;border-radius:8px;font:inherit;font-size:13px;outline:none;cursor:pointer;
  }
  .select-input:focus{border-color:var(--accent)}
  .spin{display:inline-block;width:14px;height:14px;border:2px solid #fff6;border-top-color:#fff;border-radius:50%;animation:s .7s linear infinite;vertical-align:-2px;margin-right:8px}
  @keyframes s{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<main>
  <h1>Merge files into one PDF</h1>
  <p class="sub">Select PDF, images, Word, Excel, PowerPoint or text files. Arrange the order, then merge.</p>

  <div class="card">
    <div id="drop" tabindex="0" role="button" aria-label="Choose files">
      <strong>Drop files here or click to browse</strong>
      <span>PDF · PNG · JPG · GIF · WebP · BMP · DOC/DOCX · XLS/XLSX · PPT/PPTX · ODT · RTF · TXT</span>
    </div>
    <input id="file" type="file" multiple hidden
           accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.bmp,.doc,.docx,.odt,.rtf,.xls,.xlsx,.ods,.ppt,.pptx,.odp,.txt">

    <ul id="list"></ul>

    <div class="options-bar" id="optionsBar">
      <div class="opt-group">
        <label>Page Size:</label>
        <span class="tag-a4" title="Every page will be standard A4">✓ All Pages A4 (210 × 297 mm)</span>
      </div>
      <div class="opt-group">
        <label for="orientation">Orientation:</label>
        <select id="orientation" class="select-input" aria-label="Page orientation">
          <option value="portrait" selected>All Portrait (সবগুলো A4 Portrait)</option>
          <option value="auto">Auto (পেজ অনুযায়ী Portrait / Landscape)</option>
          <option value="landscape">All Landscape (সবগুলো A4 Landscape)</option>
        </select>
      </div>
    </div>

    <div class="bar">
      <span class="count" id="count">No files selected</span>
      <span>
        <button class="link" id="clear" type="button" hidden>Clear all</button>
        <button class="primary" id="merge" type="button" disabled>Merge to PDF</button>
      </span>
    </div>
    <div id="msg" role="status"></div>
  </div>
</main>

<script>
const $ = s => document.querySelector(s);
const drop = $('#drop'), input = $('#file'), list = $('#list');
const mergeBtn = $('#merge'), clearBtn = $('#clear'), msg = $('#msg'), count = $('#count');
const OK = ['pdf','png','jpg','jpeg','gif','webp','bmp','doc','docx','odt','rtf','xls','xlsx','ods','ppt','pptx','odp','txt'];
const COLORS = {pdf:'#e11d48',doc:'#2563eb',docx:'#2563eb',odt:'#2563eb',rtf:'#2563eb',txt:'#64748b',
                xls:'#16a34a',xlsx:'#16a34a',ods:'#16a34a',ppt:'#ea580c',pptx:'#ea580c',odp:'#ea580c'};
let files = []; // {id, file, url}
let uid = 0, dragId = null, lastUrl = null;

const ext = n => (n.split('.').pop() || '').toLowerCase();
const isImg = n => ['png','jpg','jpeg','gif','webp','bmp'].includes(ext(n));
const fmt = b => b < 1024 ? b + ' B' : b < 1048576 ? (b/1024).toFixed(1) + ' KB' : (b/1048576).toFixed(1) + ' MB';

function add(fl){
  const bad = [];
  for (const f of fl){
    if (!OK.includes(ext(f.name))) { bad.push(f.name); continue; }
    files.push({id: ++uid, file: f, url: isImg(f.name) ? URL.createObjectURL(f) : null});
  }
  show(bad.length ? 'err' : '', bad.length ? 'Not supported: ' + bad.join(', ') : '');
  render();
}

function render(){
  list.innerHTML = '';
  files.forEach((it, i) => {
    const li = document.createElement('li');
    li.className = 'item'; li.draggable = true; li.dataset.id = it.id;
    const e = ext(it.file.name);
    const visual = it.url
      ? `<img class="thumb" src="${it.url}" alt="">`
      : `<div class="badge" style="background:${COLORS[e]||'#64748b'}">${e}</div>`;
    li.innerHTML = `<span class="grip" title="Drag to reorder">⋮⋮</span>${visual}
      <div class="meta"><div class="name"></div><div class="size">${i+1} · ${fmt(it.file.size)}</div></div>
      <button class="btn-i" data-a="up" title="Move up" ${i===0?'disabled':''}>↑</button>
      <button class="btn-i" data-a="down" title="Move down" ${i===files.length-1?'disabled':''}>↓</button>
      <button class="btn-i del" data-a="del" title="Remove">✕</button>`;
    li.querySelector('.name').textContent = it.file.name; // textContent: no HTML injection
    list.appendChild(li);
  });
  count.textContent = files.length ? `${files.length} file${files.length>1?'s':''} selected` : 'No files selected';
  mergeBtn.disabled = !files.length;
  clearBtn.hidden = !files.length;
}

function move(i, j){ if (j<0||j>=files.length) return; [files[i],files[j]] = [files[j],files[i]]; render(); }
function remove(i){ if (files[i].url) URL.revokeObjectURL(files[i].url); files.splice(i,1); render(); }

function show(type, html){ msg.className = type; msg.innerHTML = html || ''; if(!type) msg.style.display='none'; else msg.style.display=''; }

// click / keyboard / drop on the dropzone
drop.onclick = () => input.click();
drop.onkeydown = e => { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); input.click(); } };
input.onchange = () => { add([...input.files]); input.value=''; };
['dragenter','dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave','drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', e => add([...e.dataTransfer.files]));

// buttons inside list
list.onclick = e => {
  const b = e.target.closest('button'); if (!b) return;
  const i = [...list.children].indexOf(b.closest('li'));
  if (b.dataset.a==='up') move(i,i-1); else if (b.dataset.a==='down') move(i,i+1); else remove(i);
};

// drag to reorder
list.addEventListener('dragstart', e => { const li=e.target.closest('li'); if(!li) return; dragId=li.dataset.id; li.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; });
list.addEventListener('dragend', () => { dragId=null; render(); });
list.addEventListener('dragover', e => {
  if (!dragId) return; e.preventDefault();
  const li = e.target.closest('li'); [...list.children].forEach(c => c.classList.toggle('dragover', c===li && c.dataset.id!==dragId));
});
list.addEventListener('drop', e => {
  if (!dragId) return; e.preventDefault(); e.stopPropagation();
  const li = e.target.closest('li'); if (!li) return;
  const from = files.findIndex(f => f.id==dragId), to = files.findIndex(f => f.id==li.dataset.id);
  if (from<0||to<0||from===to) return;
  files.splice(to, 0, files.splice(from,1)[0]); render();
});

clearBtn.onclick = () => { files.forEach(f => f.url && URL.revokeObjectURL(f.url)); files=[]; show('',''); render(); };

mergeBtn.onclick = async () => {
  if (!files.length) return;
  const fd = new FormData();
  files.forEach(f => fd.append('files[]', f.file, f.file.name));
  const orientEl = $('#orientation');
  if (orientEl) fd.append('orientation', orientEl.value);
  mergeBtn.disabled = true; mergeBtn.innerHTML = '<span class="spin"></span>Merging…'; show('', '');
  try {
    const res = await fetch('merge.php', {method:'POST', body: fd});
    if (!res.ok) {
      let m = 'Server error ' + res.status;
      try { m = (await res.json()).error || m; } catch(_){}
      throw new Error(m);
    }
    const blob = await res.blob();
    if (lastUrl) URL.revokeObjectURL(lastUrl);
    lastUrl = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = lastUrl; a.download = 'merged.pdf'; a.click();
    show('ok', `Done — <b>merged.pdf</b> (${fmt(blob.size)}) downloaded. <a href="${lastUrl}" target="_blank">Open</a> · <a href="${lastUrl}" download="merged.pdf">Download again</a>`);
  } catch (err) {
    const d = document.createElement('div'); d.textContent = err.message;
    show('err', d.innerHTML);
  } finally {
    mergeBtn.disabled = !files.length; mergeBtn.textContent = 'Merge to PDF';
  }
};

render();
</script>
</body>
</html>
