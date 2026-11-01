<?php
// BMOS 3.2 — Tenants UI (Administration).
?>
<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
  <h2 class="font-semibold mb-3">Tenants</h2>
  <div hx-get="/tenants/list" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-sm text-zinc-500">Loading…</div>
  </div>
</div>
