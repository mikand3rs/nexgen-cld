<?php
// BMOS 3.2 — Files UI. Upload via presigned URL (Alpine + fetch), list via HTMX.
?>
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-4"
     x-data="{ uploading: false, progress: '' }">
  <h2 class="font-semibold mb-3">Upload file</h2>
  <form @submit.prevent="
      const tenant = document.getElementById('tenant-input').value;
      const file = $refs.file.files[0];
      if (!file) return;
      uploading = true; progress = 'Registering…';
      fetch('/objects', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Tenant-ID': tenant },
        body: JSON.stringify({ filename: file.name, media_type: file.type || 'application/octet-stream' })
      }).then(r => r.json()).then(d => {
        if (!d.upload_url) throw new Error(d.error || 'register failed');
        progress = 'Uploading bytes…';
        return fetch(d.upload_url, { method: 'PUT', body: file }).then(() => d.id);
      }).then(id => {
        progress = 'Finalizing…';
        return fetch('/objects/' + id + '/complete', {
          method: 'POST', headers: { 'X-Tenant-ID': tenant }
        }).then(r => r.json());
      }).then(d => {
        progress = 'Done: ' + (d.lifecycle_state || 'uploaded');
        htmx.trigger('#file-list', 'refresh');
      }).catch(e => {
        progress = 'Error: ' + e.message;
      }).finally(() => { uploading = false; });
    " class="flex gap-2 items-center">
    <input x-ref="file" type="file" class="text-sm flex-1" :disabled="uploading">
    <button type="submit" :disabled="uploading"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">
      Upload
    </button>
  </form>
  <div x-show="progress" x-text="progress" class="text-sm text-gray-600 mt-2"></div>
</div>

<div class="bg-white rounded-xl border border-gray-200 p-5">
  <h2 class="font-semibold mb-3">Files</h2>
  <div id="file-list" hx-get="/objects-ui/list" hx-trigger="load, refresh" hx-swap="innerHTML">
    <div class="text-sm text-gray-500">Loading…</div>
  </div>
</div>
