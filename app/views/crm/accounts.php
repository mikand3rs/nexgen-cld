<?php
// BMOS 3.2 — CRM companies: create form, search, HTMX list.
?>
<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <h2 class="font-semibold mb-3">New company</h2>
  <form hx-post="/crm/accounts/create" hx-target="#account-list" hx-swap="innerHTML"
        hx-on::after-request="this.reset()" class="grid grid-cols-2 md:grid-cols-3 gap-2">
    <input name="name" type="text" required maxlength="200" placeholder="Company name *"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="email" type="email" maxlength="200" placeholder="Email"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="phone" type="text" maxlength="50" placeholder="Phone"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="website" type="text" maxlength="200" placeholder="Website"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="industry" type="text" maxlength="100" placeholder="Industry"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">Add company</button>
  </form>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
  <div class="flex items-center justify-between mb-3">
    <h2 class="font-semibold">Companies</h2>
    <input type="text" placeholder="Search…" name="q"
           hx-get="/crm/accounts/list" hx-target="#account-list" hx-swap="innerHTML"
           hx-trigger="keyup changed delay:300ms"
           class="px-2 py-1 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
  </div>
  <div id="account-list" hx-get="/crm/accounts/list" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-sm text-zinc-500">Loading…</div>
  </div>
</div>
