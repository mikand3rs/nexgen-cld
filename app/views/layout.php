<?php
// BMOS 3.2 — Dashboard shell, Preline-inspired.
// Sidebar IA per board guidelines: COMMAND CENTER / OPERATIONS /
// AI WORKFORCE / AUTOMATION / ADMINISTRATION (subset for the foundation;
// BUSINESS/WORKFORCE/SUPPORT/ANALYTICS arrive with their modules).
// Tailwind (CDN) + HTMX + Alpine.js. Server-rendered by PHP.
$sections = [
    'COMMAND CENTER' => [
        'dashboard' => ['Dashboard', '/dashboard', 'M3 12l9-9 9 9'],
    ],
    'OPERATIONS' => [
        'tasks'     => ['Tasks', '/tasks', 'M9 12l2 2 4-4'],
        'documents' => ['Documents', '/documents', 'M7 3h8l4 4v14H7z'],
        'objects'   => ['Files', '/objects-ui', 'M4 4h16v16H4z'],
    ],
    'AI WORKFORCE' => [
        'ai' => ['AI Chat', '/ai-ui', 'M12 3l7 7-7 7-7-7z'],
    ],
    'AUTOMATION' => [
        'workflows' => ['Workflows', '/workflows', 'M4 6h16M4 12h16M4 18h16'],
    ],
    'ADMINISTRATION' => [
        'tenants' => ['Tenants', '/tenants', 'M3 7h18v10H3z'],
        'health'  => ['System Health', '/health-ui', 'M12 8v4l2 2'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title ?? 'BMOS') ?> — BMOS 3.2</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/htmx.org@1.9.12"></script>
<script src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<style>
  [x-cloak] { display: none !important; }
  .nav-active { background: #1d4ed8; color: #fff; }
  .nav-active svg { stroke: #fff; }
</style>
</head>
<body class="h-full bg-gray-50 text-gray-900" x-data="{ sidebarOpen: false }">
<div class="flex h-full">

  <!-- Sidebar (desktop) -->
  <aside class="hidden lg:flex w-64 flex-col bg-white border-r border-gray-200 shrink-0">
    <div class="px-5 py-4 border-b border-gray-200">
      <div class="font-bold text-lg text-gray-900">BMOS</div>
      <div class="text-xs text-gray-500">Business OS · 3.2</div>
    </div>
    <nav class="flex-1 px-3 py-4 space-y-4 overflow-y-auto">
      <?php foreach ($sections as $section => $items): ?>
        <div>
          <div class="px-3 mb-1 text-[11px] font-semibold tracking-wider text-gray-400"><?= $section ?></div>
          <div class="space-y-0.5">
            <?php foreach ($items as $key => [$label, $href, $icon]): ?>
              <a href="<?= $href ?>"
                 class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($active ?? '') === $key ? 'nav-active' : 'text-gray-700 hover:bg-gray-100' ?>">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/>
                </svg>
                <?= htmlspecialchars($label) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="px-5 py-3 border-t border-gray-200 text-xs text-gray-500">
      Tenant <span class="font-mono text-gray-700" id="tenant-label">—</span>
    </div>
  </aside>

  <!-- Mobile sidebar -->
  <div x-show="sidebarOpen" x-cloak class="fixed inset-0 z-40 lg:hidden">
    <div class="absolute inset-0 bg-black/50" @click="sidebarOpen = false"></div>
    <aside class="absolute left-0 top-0 h-full w-64 bg-white flex flex-col overflow-y-auto">
      <div class="px-5 py-4 border-b border-gray-200 flex justify-between items-center">
        <div class="font-bold">BMOS</div>
        <button @click="sidebarOpen = false" class="text-gray-500 text-xl">×</button>
      </div>
      <nav class="flex-1 px-3 py-4 space-y-4">
        <?php foreach ($sections as $section => $items): ?>
          <div>
            <div class="px-3 mb-1 text-[11px] font-semibold tracking-wider text-gray-400"><?= $section ?></div>
            <?php foreach ($items as $key => [$label, $href, $icon]): ?>
              <a href="<?= $href ?>"
                 class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($active ?? '') === $key ? 'nav-active' : 'text-gray-700 hover:bg-gray-100' ?>">
                <?= htmlspecialchars($label) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </nav>
    </aside>
  </div>

  <!-- Main -->
  <div class="flex-1 flex flex-col min-w-0">
    <header class="bg-white border-b border-gray-200 px-4 py-3 flex items-center gap-3 sticky top-0 z-10">
      <button @click="sidebarOpen = true" class="lg:hidden text-gray-600 text-xl px-2">☰</button>
      <h1 class="font-semibold text-lg text-gray-900"><?= htmlspecialchars($title ?? 'Dashboard') ?></h1>
      <div class="ml-auto flex items-center gap-2">
        <span class="text-xs text-gray-500 hidden sm:inline">Demo tenant UUID</span>
        <input id="tenant-input" type="text" spellcheck="false"
               class="w-52 sm:w-64 px-2 py-1.5 border border-gray-300 rounded-lg text-xs font-mono"
               value="11111111-1111-1111-1111-111111111111">
      </div>
    </header>
    <main class="flex-1 overflow-y-auto p-4 md:p-6">
      <?= $content ?? '' ?>
    </main>
  </div>
</div>
<script>
  const tenantInput = document.getElementById('tenant-input');
  const tenantLabel = document.getElementById('tenant-label');
  function syncTenant() {
    tenantLabel.textContent = (tenantInput.value || '—').slice(0, 8) + '…';
  }
  tenantInput.addEventListener('input', syncTenant);
  syncTenant();
  document.body.addEventListener('htmx:configRequest', function (e) {
    e.detail.headers['X-Tenant-ID'] = tenantInput.value;
  });
</script>
</body>
</html>
