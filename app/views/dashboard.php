<?php
// BMOS 3.2 — Dashboard (Command Center) content.
// Stats are loaded via HTMX from /dashboard/stats so the shell renders fast.
?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6"
     hx-get="/dashboard/stats" hx-trigger="load" hx-swap="innerHTML">
  <?php for ($i = 0; $i < 4; $i++): ?>
    <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 animate-pulse">
      <div class="h-4 bg-zinc-800 rounded w-1/2 mb-2"></div>
      <div class="h-8 bg-zinc-800 rounded w-1/3"></div>
    </div>
  <?php endfor; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h2 class="font-semibold mb-3">Recent tasks</h2>
    <div hx-get="/dashboard/recent-tasks" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
    <a href="/tasks" class="text-sm text-blue-600 hover:underline mt-3 inline-block">View all tasks →</a>
  </div>
  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h2 class="font-semibold mb-3">System status</h2>
    <div hx-get="/dashboard/system" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
  </div>
</div>
