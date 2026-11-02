<?php
// BMOS 3.2 — CRM activities: log form (optionally linked to a record), list.
?>
<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <h2 class="font-semibold mb-3">Log activity</h2>
  <form hx-post="/crm/activities/create" hx-target="#activity-list" hx-swap="innerHTML"
        hx-on::after-request="this.reset()" class="grid grid-cols-2 md:grid-cols-4 gap-2">
    <select name="type" required class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
      <option value="call">Call</option>
      <option value="meeting">Meeting</option>
      <option value="email">Email</option>
      <option value="note">Note</option>
      <option value="followup">Follow-up</option>
    </select>
    <input name="subject" type="text" required maxlength="200" placeholder="Subject *"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11] md:col-span-2">
    <input name="due_at" type="datetime-local"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <input name="body" type="text" maxlength="2000" placeholder="Details (optional)"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11] md:col-span-2">
    <select name="entity_type" class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
      <option value="">No link</option>
      <option value="lead">Lead</option>
      <option value="account">Company</option>
      <option value="contact">Contact</option>
    </select>
    <input name="entity_id" type="text" maxlength="36" placeholder="Record UUID (optional)"
           class="px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 md:col-span-4">Log</button>
  </form>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
  <div class="flex items-center justify-between mb-3">
    <h2 class="font-semibold">Activities</h2>
    <a href="/crm/activities/list?overdue=1" hx-get="/crm/activities/list?overdue=1" hx-target="#activity-list" hx-swap="innerHTML"
       class="text-sm text-red-300 hover:underline">Overdue only</a>
  </div>
  <div id="activity-list" hx-get="/crm/activities/list" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-sm text-zinc-500">Loading…</div>
  </div>
</div>
