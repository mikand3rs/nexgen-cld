<?php
// BMOS 3.2 — Tenants UI (Administration).
?>
<div class="bg-white rounded-xl border border-gray-200 p-5">
  <h2 class="font-semibold mb-3">Tenants</h2>
  <div hx-get="/tenants/list" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-sm text-gray-500">Loading…</div>
  </div>
</div>
