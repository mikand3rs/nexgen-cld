<?php
// BMOS 3.2 — CRM leads: create form, status filter, HTMX list.
?>
<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <h2 class="font-semibold mb-3">New lead</h2>
  <form hx-post="/crm/leads/create" hx-target="#lead-list" hx-swap="innerHTML"
        hx-on::after-request="this.reset()" class="grid grid-cols-2 md:grid-cols-3 gap-2">
    <input name="name" type="text" required maxlength="200" placeholder="Name *"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="company_name" type="text" maxlength="200" placeholder="Company"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="email" type="email" maxlength="200" placeholder="Email"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="phone" type="text" maxlength="50" placeholder="Phone"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="source" type="text" maxlength="50" placeholder="Source (website, referral…)"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">Add lead</button>
  </form>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
  <div class="flex items-center justify-between mb-3">
    <h2 class="font-semibold">Leads</h2>
    <select hx-get="/crm/leads/list" hx-target="#lead-list" hx-swap="innerHTML" name="status"
            class="px-2 py-1 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
      <option value="">All statuses</option>
      <option value="new">New</option>
      <option value="contacted">Contacted</option>
      <option value="qualified">Qualified</option>
      <option value="disqualified">Disqualified</option>
      <option value="converted">Converted</option>
    </select>
  </div>
  <div id="lead-list" hx-get="/crm/leads/list" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-sm text-zinc-500">Loading…</div>
  </div>
</div>
