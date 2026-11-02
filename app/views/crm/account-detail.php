<?php
// BMOS 3.2 — CRM company detail: info, people, notes, activities, history.
?>
<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <div class="flex items-start justify-between">
    <div>
      <h2 class="text-xl font-semibold"><?= crm_h($account['name']) ?></h2>
      <div class="text-sm text-zinc-400 mt-1">
        <?php foreach (['email','phone','website','industry','address'] as $f): ?>
          <?php if (!empty($account[$f])): ?><div><?= crm_h($account[$f]) ?></div><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div><?= crm_status_badge($account['status']) ?></div>
  </div>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <h3 class="font-semibold mb-3">People</h3>
  <?php if (empty($account['contacts'])): ?>
    <div class="text-sm text-zinc-500">No linked contacts. <a href="/crm/contacts" class="text-blue-300 hover:underline">Add one →</a></div>
  <?php else: ?>
    <ul class="space-y-2">
      <?php foreach ($account['contacts'] as $c): ?>
        <li class="text-sm border-b border-zinc-800/50 pb-2">
          <a href="/crm/contacts/<?= crm_h($c['id']) ?>" class="hover:text-blue-300 font-medium">
            <?= crm_h(trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''))) ?>
          </a>
          <?php if ($c['is_primary']) echo '<span class="text-xs text-emerald-300 ml-1">primary</span>'; ?>
          <?php if ($c['role']) echo '<span class="text-zinc-500"> · ' . crm_h($c['role']) . '</span>'; ?>
          <?php if ($c['email']) echo '<div class="text-xs text-zinc-500">' . crm_h($c['email']) . '</div>'; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h3 class="font-semibold mb-3">Notes</h3>
    <div id="notes-list" hx-get="/crm/notes/list?entity_type=account&entity_id=<?= crm_h($account['id']) ?>" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
    <form hx-post="/crm/notes/create" hx-target="#notes-list" hx-swap="innerHTML"
          hx-on::after-request="this.reset()" class="mt-3">
      <input type="hidden" name="entity_type" value="account">
      <input type="hidden" name="entity_id" value="<?= crm_h($account['id']) ?>">
      <textarea name="body" required maxlength="5000" rows="2" placeholder="Add a note… (never store passwords or keys here)"
                class="w-full px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]"></textarea>
      <button type="submit" class="mt-2 px-3 py-1.5 bg-zinc-800 rounded-lg text-sm hover:bg-zinc-700">Add note</button>
    </form>
  </div>

  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h3 class="font-semibold mb-3">Activity timeline</h3>
    <div hx-get="/crm/activities/list?entity_type=account&entity_id=<?= crm_h($account['id']) ?>" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
    <a href="/crm/activities" class="text-sm text-blue-300 hover:underline mt-3 inline-block">Log activity →</a>
  </div>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mt-4">
  <h3 class="font-semibold mb-3">Audit history</h3>
  <?php if (empty($history)): ?>
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
