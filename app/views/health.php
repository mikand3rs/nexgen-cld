<?php
// BMOS 3.2 — System Health UI (Administration). Detailed service checks.
?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h2 class="font-semibold mb-3">Services</h2>
    <div hx-get="/health-ui/services" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
  </div>
  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h2 class="font-semibold mb-3">Database</h2>
    <div hx-get="/health-ui/database" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
  </div>
</div>
