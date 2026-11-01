<?php
// BMOS 3.2 — Documents UI. Search + list via HTMX; indexing via the API.
?>
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
  <h2 class="font-semibold mb-3">Search documents</h2>
  <form hx-get="/documents/search" hx-target="#doc-results" hx-swap="innerHTML" class="flex gap-2">
    <input name="q" type="text" placeholder="Search…" required
           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm">
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
      Search
    </button>
  </form>
  <div id="doc-results" class="mt-3"></div>
</div>

<div class="bg-white rounded-xl border border-gray-200 p-5">
  <h2 class="font-semibold mb-3">All documents</h2>
  <div hx-get="/documents/list" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-sm text-gray-500">Loading…</div>
  </div>
</div>
