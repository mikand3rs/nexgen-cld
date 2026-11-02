<?php
// BMOS 3.2 — CRM overview: stat cards, search, quick links.
?>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
  <?php
  $cards = [
      ['Open leads', $stats['open_leads'], '/crm/leads'],
      ['Companies', $stats['accounts'], '/crm/accounts'],
      ['Contacts', $stats['contacts'], '/crm/contacts'],
      ['Overdue follow-ups', $stats['overdue_followups'], '/crm/activities?overdue=1'],
      ['Due this week', $stats['activities_due_week'], '/crm/activities'],
  ];
  foreach ($cards as [$label, $value, $url]) {
      $alert = ($label === 'Overdue follow-ups' && $value > 0) ? ' border-red-900/60' : '';
      echo '<a href="' . $url . '" class="bg-[#141419] rounded-2xl border border-zinc-800/70' . $alert . ' p-5 text-center hover:border-zinc-600">';
      echo '<div class="text-4xl font-semibold">' . (int)$value . '</div>';
      echo '<div class="text-xs text-zinc-500 mt-1">' . htmlspecialchars($label) . '</div>';
      echo '</a>';
  }
  ?>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <h2 class="font-semibold mb-3">Search CRM</h2>
  <input type="text" placeholder="Name, email, company…"
         hx-get="/crm/search" hx-target="#search-results" hx-swap="innerHTML"
         hx-trigger="keyup changed delay:300ms" name="q"
         class="w-full px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
  <div id="search-results" class="mt-3"></div>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
  <h2 class="font-semibold mb-3">Start</h2>
  <div class="flex flex-wrap gap-2 text-sm">
    <a href="/crm/leads" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">New lead</a>
    <a href="/crm/accounts" class="px-4 py-2 bg-zinc-800 rounded-lg hover:bg-zinc-700">New company</a>
    <a href="/crm/contacts" class="px-4 py-2 bg-zinc-800 rounded-lg hover:bg-zinc-700">New contact</a>
    <a href="/crm/activities" class="px-4 py-2 bg-zinc-800 rounded-lg hover:bg-zinc-700">Log activity</a>
  </div>
  <p class="text-xs text-zinc-500 mt-4">CRM 1.0 — leads, companies, contacts, notes, activities. Pipelines, proposals and reports land in 1.1+.</p>
</div>
