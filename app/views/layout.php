<?php
// BMOS 3.2 — Dashboard shell, Figr "Identity" dark aesthetic.
// Sidebar IA: COMMAND CENTER / OPERATIONS / AI WORKFORCE / AUTOMATION /
// ADMINISTRATION. Tailwind (CDN) + HTMX + Alpine.js. Server-rendered by PHP.
$sections = [
    'COMMAND CENTER' => [
        'dashboard' => ['Dashboard', '/dashboard', '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
    ],
    'OPERATIONS' => [
        'tasks'     => ['Tasks', '/tasks', '<path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h9"/>'],
        'documents' => ['Documents', '/documents', '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/>'],
        'objects'   => ['Files', '/objects-ui', '<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>'],
    ],
    'CRM' => [
        'crm'            => ['Overview', '/crm', '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
        'crm-leads'      => ['Leads', '/crm/leads', '<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>'],
        'crm-accounts'   => ['Companies', '/crm/accounts', '<path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M9 21v-4h6v4"/>'],
        'crm-contacts'   => ['Contacts', '/crm/contacts', '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>'],
        'crm-activities' => ['Activities', '/crm/activities', '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
    ],
    'AI WORKFORCE' => [
        'ai' => ['AI Chat', '/ai-ui', '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/>'],
    ],
    'AUTOMATION' => [
        'workflows' => ['Workflows', '/workflows', '<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2.2" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="2.2" fill="currentColor" stroke="none"/><circle cx="7" cy="18" r="2.2" fill="currentColor" stroke="none"/>'],
    ],
    'ADMINISTRATION' => [
        'tenants' => ['Tenants', '/tenants', '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>'],
        'health'  => ['System Health', '/health-ui', '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
    ],
];

function nav_items($sections, $active, $dark_link) {
    foreach ($sections as $section => $items) {
        echo '<div class="mb-5">';
        echo '<div class="px-3 mb-2 text-[10px] font-semibold tracking-[0.14em] text-zinc-500">' . $section . '</div>';
        echo '<div class="space-y-1">';
        foreach ($items as $key => [$label, $href, $icon]) {
            $isActive = ($active ?? '') === $key;
            $cls = $isActive
                ? 'bg-zinc-800 text-white'
                : 'text-zinc-400 hover:bg-zinc-800/60 hover:text-zinc-200';
            echo '<a href="' . $href . '" class="flex items-center gap-3 px-3 py-2 rounded-xl text-[14px] ' . $cls . '">';
            echo '<svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">' . $icon . '</svg>';
            echo htmlspecialchars($label);
            echo '</a>';
        }
        echo '</div></div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title ?? 'BMOS') ?> — NexGen CLD</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/htmx.org@1.9.12"></script>
<script src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<style>
  [x-cloak] { display: none !important; }
  body { font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", Inter, "Segoe UI", sans-serif; }
  .glow-edge {
    background:
      radial-gradient(600px 300px at 0% 0%, rgba(37, 64, 235, 0.14), transparent 70%);
  }
  ::-webkit-scrollbar { width: 8px; height: 8px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #27272a; border-radius: 4px; }
  input, select, textarea { color-scheme: dark; }
</style>
</head>
<body class="h-full bg-[#08080c] text-zinc-100 antialiased" x-data="{ sidebarOpen: false }">
<div class="flex h-full glow-edge">

  <!-- Sidebar (desktop) -->
  <aside class="hidden lg:flex w-[264px] flex-col shrink-0 border-r border-zinc-800/60 bg-[#0c0c11]/90">
    <!-- User profile -->
    <div class="px-5 pt-5 pb-4 flex items-center gap-3">
      <div id="avatar" class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-violet-600 flex items-center justify-center text-sm font-semibold text-white shrink-0">?</div>
      <div class="min-w-0">
        <div id="profile-name" class="text-[14px] font-medium text-zinc-100 truncate">…</div>
        <div id="profile-role" class="text-[12px] text-zinc-500 truncate">…</div>
      </div>
    </div>
    <!-- Search -->
    <div class="px-4 pb-3">
      <div class="flex items-center gap-2 bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2">
        <svg class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="text" placeholder="Search…" class="bg-transparent outline-none text-[13px] placeholder-zinc-600 w-full text-zinc-200">
        <kbd class="text-[10px] text-zinc-600 border border-zinc-800 rounded px-1">⌘K</kbd>
      </div>
    </div>
    <!-- Nav -->
    <nav class="flex-1 px-3 py-2 overflow-y-auto">
      <?php nav_items($sections, $active ?? '', true); ?>
    </nav>
    <!-- Tenant -->
    <div class="px-4 py-3 border-t border-zinc-800/60">
      <div class="text-[10px] font-semibold tracking-[0.14em] text-zinc-500 mb-2">ACTIVE TENANT</div>
      <select id="tenant-select" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl px-3 py-2 text-[13px] text-zinc-200 outline-none focus:border-zinc-600"></select>
    </div>
  </aside>

  <!-- Mobile sidebar -->
  <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-40 lg:hidden">
    <div class="absolute inset-0 bg-black/70" @click="sidebarOpen = false"></div>
    <aside class="absolute left-0 top-0 h-full w-[264px] bg-[#0c0c11] border-r border-zinc-800 flex flex-col overflow-y-auto">
      <div class="px-5 pt-5 pb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-violet-600 flex items-center justify-center text-sm font-semibold text-white">?</div>
          <div class="text-[14px] font-medium text-zinc-100">NexGen CLD</div>
        </div>
        <button @click="sidebarOpen = false" class="text-zinc-500 text-2xl leading-none px-2">×</button>
      </div>
      <nav class="flex-1 px-3 py-2">
        <?php nav_items($sections, $active ?? '', true); ?>
      </nav>
    </aside>
  </div>

  <!-- Main -->
  <div class="flex-1 flex flex-col min-w-0">
    <header class="px-5 md:px-8 py-4 flex items-center gap-3 sticky top-0 z-10 bg-[#08080c]/80 backdrop-blur">
      <button @click="sidebarOpen = true" class="lg:hidden text-zinc-400 text-xl px-2">☰</button>
      <h1 class="font-semibold text-[17px] text-zinc-100"><?= htmlspecialchars($title ?? 'Dashboard') ?></h1>
      <div class="ml-auto flex items-center gap-3">
        <span id="user-email" class="text-[13px] text-zinc-500 hidden sm:inline"></span>
        <button id="logout-btn" class="text-[13px] text-zinc-400 hover:text-zinc-100 px-3 py-1.5 border border-zinc-800 rounded-xl hover:border-zinc-600">
          Sign out
        </button>
      </div>
    </header>
    <main class="flex-1 overflow-y-auto px-5 md:px-8 pb-8">
      <?= $content ?? '' ?>
    </main>
  </div>
</div>
<script>
  // Stage A: session-based auth. Tenant comes from the server, not a header.
  (async () => {
    const res = await fetch('/auth/me');
    if (!res.ok) { window.location.href = '/login'; return; }
    const me = await res.json();
    document.getElementById('user-email').textContent = me.user.email;
    const name = (me.user.email || '?').split('@')[0];
    document.getElementById('profile-name').textContent = name.charAt(0).toUpperCase() + name.slice(1);
    document.getElementById('avatar').textContent = name.charAt(0).toUpperCase();
    const active = me.tenants.find(t => t.id === me.active_tenant);
    document.getElementById('profile-role').textContent = active ? (active.role.charAt(0).toUpperCase() + active.role.slice(1) + ' · ' + active.name) : 'No tenant';
    const sel = document.getElementById('tenant-select');
    sel.innerHTML = '';
    for (const t of me.tenants) {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = t.name + ' (' + t.role + ')';
      if (t.id === me.active_tenant) opt.selected = true;
      sel.appendChild(opt);
    }
    sel.addEventListener('change', async () => {
      await fetch('/auth/switch-tenant', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({tenant_id: sel.value}),
      });
      window.location.reload();
    });
  })();
  document.getElementById('logout-btn').addEventListener('click', async () => {
    await fetch('/auth/logout', {method: 'POST'});
    window.location.href = '/login';
  });
</script>
</body>
</html>
