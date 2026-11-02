<?php
// BMOS 3.2 — CRM contact detail: info, companies, notes, activities, history.
?>
<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <h2 class="text-xl font-semibold"><?= crm_h(trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? ''))) ?></h2>
  <div class="text-sm text-zinc-400 mt-1">
    <?php foreach (['title','email','phone'] as $f): ?>
      <?php if (!empty($contact[$f])): ?><div><?= crm_h($contact[$f]) ?></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5 mb-4">
  <h3 class="font-semibold mb-3">Companies</h3>
  <?php if (empty($contact['accounts'])): ?>
    <div class="text-sm text-zinc-500">Not linked to any company yet.</div>
  <?php else: ?>
    <ul class="space-y-2">
      <?php foreach ($contact['accounts'] as $a): ?>
        <li class="text-sm border-b border-zinc-800/50 pb-2">
          <a href="/crm/accounts/<?= crm_h($a['id']) ?>" class="hover:text-blue-300 font-medium"><?= crm_h($a['name']) ?></a>
          <?php if ($a['role']) echo '<span class="text-zinc-500"> · ' . crm_h($a['role']) . '</span>'; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="grid md:grid-cols-2 gap-4">
  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h3 class="font-semibold mb-3">Notes</h3>
    <div id="notes-list" hx-get="/crm/notes/list?entity_type=contact&entity_id=<?= crm_h($contact['id']) ?>" hx-trigger="load" hx-swap="innerHTML">
      <div class="text-sm text-zinc-500">Loading…</div>
    </div>
    <form hx-post="/crm/notes/create" hx-target="#notes-list" hx-swap="innerHTML"
          hx-on::after-request="this.reset()" class="mt-3">
      <input type="hidden" name="entity_type" value="contact">
      <input type="hidden" name="entity_id" value="<?= crm_h($contact['id']) ?>">
      <textarea name="body" required maxlength="5000" rows="2" placeholder="Add a note… (never store passwords or keys here)"
                class="w-full px-3 py-2 border border-zinc-700 rounded-lg text-sm bg-[#0c0c11]"></textarea>
      <button type="submit" class="mt-2 px-3 py-1.5 bg-zinc-800 rounded-lg text-sm hover:bg-zinc-700">Add note</button>
    </form>
  </div>

  <div class="bg-[#141419] rounded-2xl border border-zinc-800/70 p-5">
    <h3 class="font-semibold mb-3">Activity timeline</h3>
    <div hx-get="/crm/activities/list?entity_type=contact&entity_id=<?= crm_h($contact['id']) ?>" hx-trigger="load" hx-swap="innerHTML">
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
