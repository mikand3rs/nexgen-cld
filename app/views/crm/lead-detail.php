<?php
// BMOS 3.2 — CRM lead detail: info, status, convert, notes, activities, history.
?>
<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <div class="flex items-start justify-between">
    <div>
      <h2 class="text-xl font-semibold"><?= crm_h($lead['name']) ?></h2>
      <div class="text-sm text-zinc-400 mt-1">
        <?php if ($lead['company_name']) echo crm_h($lead['company_name']) . ' · '; ?>
        <?php if ($lead['email']) echo crm_h($lead['email']) . ' · '; ?>
        <?php if ($lead['phone']) echo crm_h($lead['phone']); ?>
      </div>
      <?php if ($lead['source']) echo '<div class="text-xs text-zinc-500 mt-1">Source: ' . crm_h($lead['source']) . '</div>'; ?>
    </div>
    <div><?= crm_status_badge($lead['status']) ?></div>
  </div>

  <?php if ($lead['status'] !== 'converted'): ?>
  <div class="flex flex-wrap items-center gap-2 mt-4" x-data="{ converting: false, error: '' }">
    <form hx-post="/crm/leads/<?= crm_h($lead['id']) ?>/status" hx-target="#lead-list" hx-swap="none"
          hx-on::after-request="location.reload()" class="flex items-center gap-2">
      <select name="status" class="px-2 py-1.5 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]">
        <?php foreach (['new','contacted','qualified','disqualified'] as $s): ?>
          <option value="<?= $s ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="px-3 py-1.5 bg-zinc-800 rounded-lg text-sm hover:bg-zinc-700">Set status</button>
    </form>
    <button @click="converting = true; fetch('/crm/leads/<?= crm_h($lead['id']) ?>/convert', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: ''}).then(r => r.json()).then(j => { if (j.ok) window.location = '/crm/accounts/' + j.account_id; else { error = j.error || 'failed'; converting = false; } })"
            :disabled="converting"
            class="px-3 py-1.5 bg-emerald-700 text-white rounded-lg text-sm hover:bg-emerald-600 disabled:opacity-50">
      <span x-text="converting ? 'Converting…' : 'Qualify → convert to customer'"></span>
    </button>
    <span x-text="error" class="text-sm text-red-400"></span>
  </div>
  <?php else: ?>
  <div class="text-sm text-zinc-400 mt-4">
    Converted <?= $lead['converted_at'] ? crm_h(date('M j, Y', strtotime($lead['converted_at']))) : '' ?>
    <?php if ($lead['converted_account_id']): ?>
      → <a href="/crm/accounts/<?= crm_h($lead['converted_account_id']) ?>" class="text-blue-300 hover:underline">view company</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div id="lead-list" class="hidden"></div>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h3 class="font-semibold mb-3">Notes</h3>
    <div id="notes-list" hx-get="/crm/notes/list?entity_type=lead&entity_id=<?= crm_h($lead['id']) ?>" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
    <form hx-post="/crm/notes/create" hx-target="#notes-list" hx-swap="innerHTML"
          hx-on::after-request="this.reset()" class="mt-3">
      <input type="hidden" name="entity_type" value="lead">
      <input type="hidden" name="entity_id" value="<?= crm_h($lead['id']) ?>">
      <textarea name="body" required maxlength="5000" rows="2" placeholder="Add a note… (never store passwords or keys here)"
                class="w-full px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]"></textarea>
      <button type="submit" class="mt-2 px-3 py-1.5 bg-zinc-800 rounded-lg text-sm hover:bg-zinc-700">Add note</button>
    </form>
  </div>

  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h3 class="font-semibold mb-3">Activity timeline</h3>
    <div hx-get="/crm/activities/list?entity_type=lead&entity_id=<?= crm_h($lead['id']) ?>" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
    <a href="/crm/activities" class="text-sm text-blue-300 hover:underline mt-3 inline-block">Log activity →</a>
  </div>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mt-4">
  <h3 class="font-semibold mb-3">Audit history</h3>
  <?php if (!$history): ?>
    <div class="text-sm text-zinc-500">No audit events.</div>
  <?php else: ?>
    <ul class="space-y-1 text-sm">
      <?php foreach ($history as $h): ?>
        <li class="text-zinc-400">
          <span class="text-zinc-200"><?= crm_h($h['action']) ?></span>
          <span class="text-xs text-zinc-500">· <?= crm_h($h['actor']) ?> · <?= crm_h(date('M j, Y g:i a', strtotime($h['created_at']))) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
