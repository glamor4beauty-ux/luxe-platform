/* Video Upload — admin tab (layout pass, no backend yet)
   Renders the upload form + bucket browser into #vidupRoot.
   Storage backend will be Backblaze B2 (S3-compatible). */
(function(){
  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function fmtSize(b){
    b = Number(b) || 0;
    if (b < 1024) return b + ' B';
    if (b < 1024*1024) return (b/1024).toFixed(1) + ' KB';
    if (b < 1024*1024*1024) return (b/1024/1024).toFixed(1) + ' MB';
    return (b/1024/1024/1024).toFixed(2) + ' GB';
  }

  window.vidupLoad = function(){
    var root = document.getElementById('vidupRoot');
    if (!root) return;
    root.innerHTML = renderShell();
  };

  function renderShell(){
    return ''
      + '<div style="display:grid;grid-template-columns:minmax(0,360px) 1fr;gap:22px;align-items:start">'

        /* ─── LEFT: Upload form ─── */
        + '<div style="background:#131720;border:1px solid #1e2330;border-radius:12px;padding:20px;position:sticky;top:0">'
          + '<h3 style="margin:0 0 14px;color:#e8eaf0;font-size:16px">Upload Video</h3>'
          + '<div style="font-size:11px;color:#8a8fa8;margin-bottom:14px">Backblaze B2 bucket · Up to 20 GB per file</div>'
          + fld('Stage Name',      'vidupStage')
          + fld('Performer Email', 'vidupEmail', '', 'email')
          + '<div style="margin-bottom:14px">'
            + '<label style="display:block;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;margin-bottom:6px">File</label>'
            + '<div id="vidupDrop" '
                + 'ondragover="vidupDragOver(event)" '
                + 'ondragleave="vidupDragLeave(event)" '
                + 'ondrop="vidupDrop(event)" '
                + 'onclick="document.getElementById(\'vidupFile\').click()" '
                + 'style="border:2px dashed #3a2d50;background:#0e1117;border-radius:10px;padding:22px 14px;text-align:center;cursor:pointer;transition:border-color .15s ease,background .15s ease">'
              + '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#8a8fa8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>'
              + '<div style="color:#e8eaf0;font-size:12px;font-weight:600;margin-bottom:3px">Drag &amp; drop a file here</div>'
              + '<div style="color:#8a8fa8;font-size:11px">or click to choose from your device, SD card, or USB drive</div>'
              + '<div style="color:#6a6f88;font-size:10px;margin-top:5px">Up to 20&nbsp;GB</div>'
            + '</div>'
            + '<input id="vidupFile" type="file" accept="video/*" onchange="vidupFileSelected(this)" style="display:none"/>'
            + '<div id="vidupFileMeta" style="font-size:11px;color:#8a8fa8;margin-top:8px;min-height:14px"></div>'
          + '</div>'
          + '<button id="vidupBtn" onclick="vidupStart()" style="width:100%;padding:11px;background:#d4a830;color:#0e1117;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px">Upload</button>'
          + '<div id="vidupProgWrap" style="display:none;margin-top:14px">'
            + '<div style="height:8px;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;overflow:hidden"><div id="vidupProgBar" style="height:100%;width:0;background:linear-gradient(90deg,#16a34a,#22c55e);transition:width .2s"></div></div>'
            + '<div id="vidupProgText" style="font-size:11px;color:#8a8fa8;margin-top:6px">Starting…</div>'
          + '</div>'
          + '<div style="font-size:11px;color:#6a6f88;margin-top:14px;line-height:1.5">'
            + 'Files upload directly to Backblaze B2 in chunks. The browser handles the transfer — your server is not involved in the file data path. Large files survive network interruptions via multipart upload.'
          + '</div>'
        + '</div>'

        /* ─── RIGHT: Bucket browser ─── */
        + '<div>'
          + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:14px;flex-wrap:wrap">'
            + '<h3 style="margin:0;color:#e8eaf0;font-size:16px">Files in Bucket</h3>'
            + '<div style="display:flex;gap:10px;align-items:center">'
              + '<input id="vidupSearch" placeholder="Search by filename, stage, or email…" oninput="vidupSearch(this.value)" style="background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:7px 11px;color:#e8eaf0;font-size:12px;width:300px"/>'
              + '<button onclick="vidupLoad()" style="padding:7px 14px;background:transparent;border:1px solid #2a2f3e;color:#8a8fa8;border-radius:7px;font-size:12px;cursor:pointer">Refresh</button>'
            + '</div>'
          + '</div>'
          + '<div id="vidupList" style="background:#131720;border:1px solid #1e2330;border-radius:10px;max-height:560px;overflow:auto">'
            + renderListPlaceholder()
          + '</div>'
        + '</div>'
      + '</div>';
  }

  function renderListPlaceholder(){
    // Asynchronously load real bucket contents; show a loading row until ready.
    setTimeout(refreshBucketList, 0);
    return '<div style="padding:24px;text-align:center;color:#8a8fa8;font-size:13px">Loading bucket…</div>';
  }

  async function refreshBucketList(){
    var wrap = document.getElementById('vidupList');
    if (!wrap) return;
    try {
      var d = await fetch('/api/vidup.php?action=list').then(function(r){ return r.json(); });
      if (!d.success) {
        wrap.innerHTML = '<div style="padding:24px;color:#f87171;font-size:13px">Failed to load: ' + esc(d.error||'unknown') + '</div>';
        return;
      }
      var items = d.items || [];
      if (!items.length) {
        wrap.innerHTML = '<div style="padding:28px;text-align:center;color:#6a6f88;font-size:13px">Bucket is empty.</div>';
        return;
      }
      wrap.innerHTML = '<table style="width:100%;border-collapse:collapse">'
        + '<thead><tr style="background:#0e1117;position:sticky;top:0;z-index:2">'
          + th('Filename') + th('Size') + th('Uploaded') + th('Actions','right')
        + '</tr></thead>'
        + '<tbody>' + items.map(itemRowHtml).join('') + '</tbody>'
        + '</table>';
    } catch(e) {
      wrap.innerHTML = '<div style="padding:24px;color:#f87171;font-size:13px">Error: ' + esc(e.message) + '</div>';
    }
  }

  function itemRowHtml(it){
    var keyJs = it.key.replace(/'/g, "\\'");
    var actions = '<button onclick="vidupDownload(\''+keyJs+'\')" style="padding:5px 10px;background:transparent;border:1px solid #2563eb;color:#2563eb;border-radius:6px;font-size:11px;cursor:pointer;margin-right:4px">Download</button>'
      + '<button onclick="vidupDelete(\''+keyJs+'\')" style="padding:5px 10px;background:transparent;border:1px solid #f87171;color:#f87171;border-radius:6px;font-size:11px;cursor:pointer">Delete</button>';
    var when = (it.last_modified||'').replace('T',' ').replace(/\.\d+Z?$/,'');
    return '<tr style="border-bottom:1px solid #1e2330">'
      + '<td style="padding:10px 12px;color:#e8eaf0;font-size:13px;word-break:break-all">'+esc(it.key)+'</td>'
      + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap">'+fmtSize(it.size)+'</td>'
      + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap">'+esc(when)+'</td>'
      + '<td style="padding:10px 12px;text-align:right;white-space:nowrap">'+actions+'</td>'
      + '</tr>';
  }

  function th(label, align){
    return '<th style="padding:11px 12px;text-align:'+(align||'left')+';font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">'+esc(label)+'</th>';
  }
  function rowHtml(r){
    var badge = '<span style="display:inline-block;padding:3px 8px;background:#16a34a;color:#fff;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase">'+esc(r.status)+'</span>';
    var actions = '<button onclick="vidupDownload(\''+esc(r.name)+'\')" style="padding:5px 10px;background:transparent;border:1px solid #2563eb;color:#2563eb;border-radius:6px;font-size:11px;cursor:pointer;margin-right:4px">Download</button>'
      + '<button onclick="vidupDelete(\''+esc(r.name)+'\')" style="padding:5px 10px;background:transparent;border:1px solid #f87171;color:#f87171;border-radius:6px;font-size:11px;cursor:pointer">Delete</button>';
    return '<tr style="border-bottom:1px solid #1e2330">'
      + '<td style="padding:10px 12px;color:#e8eaf0;font-size:13px;word-break:break-all">'+esc(r.name)+'</td>'
      + '<td style="padding:10px 12px;color:#e8eaf0;font-size:13px">'+esc(r.stage)+'</td>'
      + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px">'+esc(r.email)+'</td>'
      + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap">'+fmtSize(r.size)+'</td>'
      + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap">'+esc(r.date)+'</td>'
      + '<td style="padding:10px 12px">'+badge+'</td>'
      + '<td style="padding:10px 12px;text-align:right;white-space:nowrap">'+actions+'</td>'
      + '</tr>';
  }

  function fld(label, id, value, type){
    return '<div style="margin-bottom:14px">'
      + '<label style="display:block;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;margin-bottom:4px">'+esc(label)+'</label>'
      + '<input id="'+id+'" type="'+(type||'text')+'" value="'+esc(value||'')+'" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:9px;color:#e8eaf0;font-size:13px;box-sizing:border-box"/>'
      + '</div>';
  }

  /* ── Drag-and-drop + file selection ── */
  var MAX_SIZE = 20 * 1024 * 1024 * 1024;
  var PART_SIZE = 16 * 1024 * 1024;

  function setSelectedFile(file){
    if (!file) return;
    if (file.size > MAX_SIZE) {
      alert('File too large (max 20 GB). This file is ' + fmtSize(file.size) + '.');
      return;
    }
    var input = document.getElementById('vidupFile');
    try {
      var dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
    } catch(e) {
      window.__vidupAdminDroppedFile = file;
    }
    var meta = document.getElementById('vidupFileMeta');
    meta.innerHTML = esc(file.name) + ' &middot; ' + fmtSize(file.size) + ' &middot; ' + esc(file.type || 'unknown');
  }

  window.vidupFileSelected = function(input){
    if (!input.files || !input.files[0]) {
      document.getElementById('vidupFileMeta').textContent = '';
      return;
    }
    setSelectedFile(input.files[0]);
  };

  window.vidupDragOver = function(ev){
    ev.preventDefault();
    var zone = document.getElementById('vidupDrop');
    if (zone) { zone.style.borderColor = '#d4a830'; zone.style.background = '#1a1325'; }
  };
  window.vidupDragLeave = function(ev){
    ev.preventDefault();
    var zone = document.getElementById('vidupDrop');
    if (zone) { zone.style.borderColor = '#3a2d50'; zone.style.background = '#0e1117'; }
  };
  window.vidupDrop = function(ev){
    ev.preventDefault();
    var zone = document.getElementById('vidupDrop');
    if (zone) { zone.style.borderColor = '#3a2d50'; zone.style.background = '#0e1117'; }
    var file = (ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0]) || null;
    if (!file) return;
    setSelectedFile(file);
  };

  window.vidupStart = async function(){
    var stage = (document.getElementById('vidupStage').value||'').trim();
    var email = (document.getElementById('vidupEmail').value||'').trim();
    var fileEl = document.getElementById('vidupFile');
    var file = (fileEl.files && fileEl.files[0]) || window.__vidupAdminDroppedFile || null;
    if (!stage) { alert('Stage Name is required.'); return; }
    if (!email) { alert('Performer Email is required.'); return; }
    if (!file)  { alert('Please choose a file.'); return; }
    if (file.size > MAX_SIZE) { alert('File too large (max 20 GB).'); return; }

    var btn = document.getElementById('vidupBtn');
    btn.disabled = true; btn.textContent = 'Starting…';
    var wrap = document.getElementById('vidupProgWrap');
    var bar  = document.getElementById('vidupProgBar');
    var txt  = document.getElementById('vidupProgText');
    wrap.style.display = 'block';
    bar.style.width = '0%';
    bar.style.background = 'linear-gradient(90deg,#16a34a,#22c55e)';
    txt.textContent = 'Requesting upload session…';

    var initRes;
    try {
      initRes = await fetch('/api/vidup.php?action=init_multipart', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
          stage_name: stage, performer_email: email,
          filename: file.name, size: file.size,
          content_type: file.type || 'application/octet-stream',
          part_size: PART_SIZE
        })
      }).then(function(r){ return r.json(); });
    } catch(e) {
      txt.textContent = 'Init failed: ' + e.message;
      btn.disabled = false; btn.textContent = 'Upload';
      return;
    }
    if (!initRes.success) {
      txt.textContent = 'Init failed: ' + (initRes.error||'unknown');
      bar.style.background = '#dc2626';
      btn.disabled = false; btn.textContent = 'Retry';
      return;
    }

    var key = initRes.key, uploadId = initRes.upload_id, parts = initRes.parts || [];
    var partSize = initRes.part_size || PART_SIZE;
    var uploadedParts = [];

    txt.textContent = 'Uploading… 0%';
    btn.textContent = 'Uploading…';

    try {
      for (var i = 0; i < parts.length; i++) {
        var partNum = parts[i].part_number;
        var url = parts[i].url;
        var start = (partNum - 1) * partSize;
        var end = Math.min(start + partSize, file.size);
        var blob = file.slice(start, end);

        var etag = await uploadPart(url, blob, function(s){
          return function(loaded){
            var sent = s + loaded;
            var pct = (sent / file.size) * 100;
            bar.style.width = pct.toFixed(1) + '%';
            txt.textContent = 'Uploading… ' + pct.toFixed(1) + '%  (' + fmtSize(sent) + ' / ' + fmtSize(file.size) + ')';
          };
        }(start));
        uploadedParts.push({ part_number: partNum, etag: etag });
      }

      txt.textContent = 'Finalizing…';
      var completeRes = await fetch('/api/vidup.php?action=complete_multipart', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ key: key, upload_id: uploadId, parts: uploadedParts })
      }).then(function(r){ return r.json(); });
      if (!completeRes.success) throw new Error(completeRes.error || 'complete failed');

      bar.style.width = '100%';
      txt.textContent = 'Done. Uploaded as ' + key;
      btn.textContent = 'Upload another';
      btn.disabled = false;
      document.getElementById('vidupStage').value = '';
      document.getElementById('vidupEmail').value = '';
      document.getElementById('vidupFile').value = '';
      document.getElementById('vidupFileMeta').textContent = '';
      window.__vidupAdminDroppedFile = null;
      refreshBucketList();
    } catch(e) {
      bar.style.background = '#dc2626';
      txt.textContent = 'Upload failed: ' + e.message;
      btn.disabled = false; btn.textContent = 'Retry';
      try {
        await fetch('/api/vidup.php?action=abort_multipart', {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ key: key, upload_id: uploadId })
        });
      } catch(_) {}
    }
  };

  function uploadPart(presignedUrl, blob, onProgress){
    return new Promise(function(resolve, reject){
      var xhr = new XMLHttpRequest();
      xhr.open('PUT', presignedUrl, true);
      xhr.upload.onprogress = function(ev){ if (ev.lengthComputable) onProgress(ev.loaded); };
      xhr.onload = function(){
        if (xhr.status >= 200 && xhr.status < 300) {
          var etag = xhr.getResponseHeader('ETag') || xhr.getResponseHeader('etag') || '';
          etag = etag.replace(/"/g, '');
          if (!etag) reject(new Error('No ETag returned (bucket CORS may need expose-headers: etag)'));
          else resolve(etag);
        } else {
          reject(new Error('Part PUT ' + xhr.status + ': ' + (xhr.responseText||'').slice(0,200)));
        }
      };
      xhr.onerror = function(){ reject(new Error('Network error during part upload')); };
      xhr.send(blob);
    });
  }

  window.vidupSearch = function(v){
    var q = (v||'').toLowerCase();
    var rows = document.querySelectorAll('#vidupList table tbody tr');
    rows.forEach(function(tr){
      var hit = !q || tr.textContent.toLowerCase().indexOf(q) > -1;
      tr.style.display = hit ? '' : 'none';
    });
  };

  window.vidupDownload = async function(key){
    try {
      var d = await fetch('/api/vidup.php?action=download_url&key=' + encodeURIComponent(key)).then(function(r){ return r.json(); });
      if (!d.success) { alert('Download error: ' + (d.error||'')); return; }
      window.open(d.url, '_blank');
    } catch(e) { alert('Error: ' + e.message); }
  };

  window.vidupDelete = async function(key){
    if (!confirm('Delete "' + key + '" from the bucket? This cannot be undone.')) return;
    try {
      var d = await fetch('/api/vidup.php?action=delete', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ key: key })
      }).then(function(r){ return r.json(); });
      if (!d.success) { alert('Delete error: ' + (d.error||'')); return; }
      refreshBucketList();
    } catch(e) { alert('Error: ' + e.message); }
  };
})();
