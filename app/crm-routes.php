<?php
// BMOS 3.2 — CRM 1.0 routes. Included from index.php for paths under /crm.
// All routes are tenant-scoped via Auth::requireTenant(); pages additionally
// require a login session. HTML partials follow the tasks.php HTMX pattern.

// ---------------------------------------------------------------- helpers
function crm_with(callable $fn)
{
    $tenantId = Auth::requireTenant();
    $pdo = Db::connect();
    $pdo->beginTransaction();
    $pdo->exec("SET LOCAL app.current_tenant = '{$tenantId}'");
    $crm = new Crm($pdo, $tenantId, Auth::userEmail() ?? 'api');
    try {
        $result = $fn($crm, $pdo, $tenantId);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function crm_uuid_from_path(string $path, string $prefix): ?string
{
    $rest = substr($path, strlen($prefix));
    if (preg_match('#^/([0-9a-f-]{36})(/|$)#i', $rest, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

function crm_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function crm_status_badge(string $status): string
{
    $map = [
        'new' => 'bg-blue-900/40 text-blue-300',
        'contacted' => 'bg-amber-900/40 text-amber-300',
        'qualified' => 'bg-emerald-900/40 text-emerald-300',
        'disqualified' => 'bg-zinc-800 text-zinc-400',
        'converted' => 'bg-purple-900/40 text-purple-300',
        'prospect' => 'bg-amber-900/40 text-amber-300',
        'customer' => 'bg-emerald-900/40 text-emerald-300',
        'former' => 'bg-zinc-800 text-zinc-400',
        'open' => 'bg-amber-900/40 text-amber-300',
        'done' => 'bg-emerald-900/40 text-emerald-300',
    ];
    $cls = $map[$status] ?? 'bg-zinc-800 text-zinc-300';
    return '<span class="px-2 py-0.5 rounded text-xs ' . $cls . '">' . crm_h($status) . '</span>';
}

function crm_render_lead_list(array $leads): void
{
    if (!$leads) {
        echo '<div class="text-sm text-zinc-500">No leads yet.</div>';
        return;
    }
    echo '<ul class="space-y-2">';
    foreach ($leads as $l) {
        echo '<li class="flex items-center gap-3 text-sm border-b border-zinc-800/50 pb-2">';
        echo '<a href="/crm/leads/' . crm_h($l['id']) . '" class="flex-1 hover:text-blue-300">';
        echo '<span class="font-medium">' . crm_h($l['name']) . '</span>';
        if ($l['company_name']) {
            echo ' <span class="text-zinc-500">· ' . crm_h($l['company_name']) . '</span>';
        }
        if ($l['email']) {
            echo '<div class="text-xs text-zinc-500">' . crm_h($l['email']) . '</div>';
        }
        echo '</a>' . crm_status_badge($l['status']);
        echo '</li>';
    }
    echo '</ul>';
}

function crm_render_account_list(array $accounts): void
{
    if (!$accounts) {
        echo '<div class="text-sm text-zinc-500">No companies yet.</div>';
        return;
    }
    echo '<ul class="space-y-2">';
    foreach ($accounts as $a) {
        echo '<li class="flex items-center gap-3 text-sm border-b border-zinc-800/50 pb-2">';
        echo '<a href="/crm/accounts/' . crm_h($a['id']) . '" class="flex-1 hover:text-blue-300">';
        echo '<span class="font-medium">' . crm_h($a['name']) . '</span>';
        if ($a['industry']) {
            echo ' <span class="text-zinc-500">· ' . crm_h($a['industry']) . '</span>';
        }
        echo '</a>' . crm_status_badge($a['status']);
        echo '</li>';
    }
    echo '</ul>';
}

function crm_render_contact_list(array $contacts): void
{
    if (!$contacts) {
        echo '<div class="text-sm text-zinc-500">No contacts yet.</div>';
        return;
    }
    echo '<ul class="space-y-2">';
    foreach ($contacts as $c) {
        $name = trim($c['first_name'] . ' ' . ($c['last_name'] ?? ''));
        echo '<li class="flex items-center gap-3 text-sm border-b border-zinc-800/50 pb-2">';
        echo '<a href="/crm/contacts/' . crm_h($c['id']) . '" class="flex-1 hover:text-blue-300">';
        echo '<span class="font-medium">' . crm_h($name) . '</span>';
        if ($c['title']) {
            echo ' <span class="text-zinc-500">· ' . crm_h($c['title']) . '</span>';
        }
        if ($c['email']) {
            echo '<div class="text-xs text-zinc-500">' . crm_h($c['email']) . '</div>';
        }
        echo '</a></li>';
    }
    echo '</ul>';
}

function crm_render_activity_list(array $acts): void
{
    if (!$acts) {
        echo '<div class="text-sm text-zinc-500">No activities yet.</div>';
        return;
    }
    echo '<ul class="space-y-2">';
    foreach ($acts as $a) {
        $done = $a['completed_at'] !== null;
        $overdue = !$done && $a['due_at'] !== null && strtotime($a['due_at']) < time();
        echo '<li class="flex items-center gap-3 text-sm border-b border-zinc-800/50 pb-2">';
        if (!$done) {
            echo '<button hx-post="/crm/activities/' . crm_h($a['id']) . '/complete" hx-target="#activity-list" hx-swap="innerHTML"'
                . ' class="w-5 h-5 rounded border border-zinc-700 flex items-center justify-center text-xs shrink-0" title="Mark done"></button>';
        } else {
            echo '<span class="w-5 h-5 rounded bg-emerald-500 text-white flex items-center justify-center text-xs shrink-0">✓</span>';
        }
        echo '<div class="flex-1' . ($done ? ' line-through text-zinc-500' : '') . '">';
        echo '<span class="px-1.5 py-0.5 rounded text-[11px] bg-zinc-800 text-zinc-300 mr-2">' . crm_h($a['type']) . '</span>';
        echo crm_h($a['subject']);
        if ($a['due_at']) {
            $cls = $overdue ? 'text-red-400' : 'text-zinc-500';
            echo ' <span class="text-xs ' . $cls . '">due ' . crm_h(date('M j', strtotime($a['due_at']))) . '</span>';
        }
        echo '</div></li>';
    }
    echo '</ul>';
}

function crm_render_notes(array $notes): void
{
    if (!$notes) {
        echo '<div class="text-sm text-zinc-500">No notes yet.</div>';
        return;
    }
    echo '<ul class="space-y-3">';
    foreach ($notes as $n) {
        echo '<li class="text-sm border-b border-zinc-800/50 pb-2">';
        echo '<div class="whitespace-pre-wrap">' . crm_h($n['body']) . '</div>';
        echo '<div class="text-xs text-zinc-500 mt-1">' . crm_h($n['created_by'] ?? '') . ' · '
            . crm_h(date('M j, Y g:i a', strtotime($n['created_at']))) . '</div>';
        echo '</li>';
    }
    echo '</ul>';
}

function crm_page(string $title, string $active, string $view, array $vars = []): void
{
    Auth::requireLogin();
    header('Content-Type: text/html; charset=utf-8');
    extract($vars);
    ob_start();
    require __DIR__ . '/views/crm/' . $view;
    $content = ob_get_clean();
    require __DIR__ . '/views/layout.php';
}

// ------------------------------------------------------------------ overview
if ($path === '/crm' && $method === 'GET') {
    try {
        $stats = crm_with(fn($crm) => $crm->stats());
    } catch (Throwable $e) {
        $stats = ['open_leads' => 0, 'accounts' => 0, 'contacts' => 0, 'overdue_followups' => 0, 'activities_due_week' => 0];
    }
    crm_page('CRM · Overview', 'crm', 'overview.php', ['stats' => $stats]);
    return;
}

// ------------------------------------------------------------------ leads
if ($path === '/crm/leads' && $method === 'GET') {
    crm_page('CRM · Leads', 'crm-leads', 'leads.php');
    return;
}

if ($path === '/crm/leads/list' && $method === 'GET') {
    $status = $_GET['status'] ?? null;
    try {
        $leads = crm_with(fn($crm) => $crm->listLeads($status ?: null));
        crm_render_lead_list($leads);
    } catch (Throwable $e) {
        echo '<div class="text-sm text-red-400">Could not load leads.</div>';
    }
    return;
}

if ($path === '/crm/leads/create' && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        crm_with(fn($crm) => $crm->createLead($_POST));
        $leads = crm_with(fn($crm) => $crm->listLeads());
        crm_render_lead_list($leads);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo '<div class="text-sm text-red-400">' . crm_h($e->getMessage()) . '</div>';
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<div class="text-sm text-red-400">Could not create lead.</div>';
    }
    return;
}

if (preg_match('#^/crm/leads/([0-9a-f-]{36})/status$#i', $path, $m) && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        crm_with(fn($crm) => $crm->updateLeadStatus(strtolower($m[1]), (string)($_POST['status'] ?? '')));
        $leads = crm_with(fn($crm) => $crm->listLeads());
        crm_render_lead_list($leads);
    } catch (Throwable $e) {
        http_response_code(400);
        echo '<div class="text-sm text-red-400">' . crm_h($e->getMessage()) . '</div>';
    }
    return;
}

if (preg_match('#^/crm/leads/([0-9a-f-]{36})/convert$#i', $path, $m) && $method === 'POST') {
    header('Content-Type: application/json');
    try {
        $result = crm_with(fn($crm) => $crm->convertLead(strtolower($m[1]), $_POST));
        echo json_encode(['ok' => true] + $result);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

if (($leadId = crm_uuid_from_path($path, '/crm/leads')) && $method === 'GET') {
    try {
        $lead = crm_with(fn($crm) => $crm->getLead($leadId));
        if (!$lead) {
            http_response_code(404);
            echo 'Lead not found';
            return;
        }
        $notes = crm_with(fn($crm) => $crm->listNotes('lead', $leadId));
        $activities = crm_with(fn($crm) => $crm->listActivities('lead', $leadId));
        $history = crm_with(fn($crm) => $crm->auditHistory('lead', $leadId));
        crm_page('CRM · Lead', 'crm-leads', 'lead-detail.php', [
            'lead' => $lead, 'notes' => $notes, 'activities' => $activities, 'history' => $history,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Could not load lead.';
    }
    return;
}

// ------------------------------------------------------------------ accounts
if ($path === '/crm/accounts' && $method === 'GET') {
    crm_page('CRM · Companies', 'crm-accounts', 'accounts.php');
    return;
}

if ($path === '/crm/accounts/list' && $method === 'GET') {
    try {
        $accounts = crm_with(fn($crm) => $crm->listAccounts($_GET['q'] ?? null));
        crm_render_account_list($accounts);
    } catch (Throwable $e) {
        echo '<div class="text-sm text-red-400">Could not load companies.</div>';
    }
    return;
}

if ($path === '/crm/accounts/create' && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        crm_with(fn($crm) => $crm->createAccount($_POST));
        $accounts = crm_with(fn($crm) => $crm->listAccounts());
        crm_render_account_list($accounts);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo '<div class="text-sm text-red-400">' . crm_h($e->getMessage()) . '</div>';
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<div class="text-sm text-red-400">Could not create company.</div>';
    }
    return;
}

if (($accountId = crm_uuid_from_path($path, '/crm/accounts')) && $method === 'GET') {
    try {
        $account = crm_with(fn($crm) => $crm->getAccount($accountId));
        if (!$account) {
            http_response_code(404);
            echo 'Company not found';
            return;
        }
        $notes = crm_with(fn($crm) => $crm->listNotes('account', $accountId));
        $activities = crm_with(fn($crm) => $crm->listActivities('account', $accountId));
        $history = crm_with(fn($crm) => $crm->auditHistory('account', $accountId));
        crm_page('CRM · Company', 'crm-accounts', 'account-detail.php', [
            'account' => $account, 'notes' => $notes, 'activities' => $activities, 'history' => $history,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Could not load company.';
    }
    return;
}

// ------------------------------------------------------------------ contacts
if ($path === '/crm/contacts' && $method === 'GET') {
    crm_page('CRM · Contacts', 'crm-contacts', 'contacts.php');
    return;
}

if ($path === '/crm/contacts/list' && $method === 'GET') {
    try {
        $contacts = crm_with(fn($crm) => $crm->listContacts($_GET['q'] ?? null));
        crm_render_contact_list($contacts);
    } catch (Throwable $e) {
        echo '<div class="text-sm text-red-400">Could not load contacts.</div>';
    }
    return;
}

if ($path === '/crm/contacts/create' && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        crm_with(fn($crm) => $crm->createContact($_POST));
        $contacts = crm_with(fn($crm) => $crm->listContacts());
        crm_render_contact_list($contacts);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo '<div class="text-sm text-red-400">' . crm_h($e->getMessage()) . '</div>';
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<div class="text-sm text-red-400">Could not create contact.</div>';
    }
    return;
}

if ($path === '/crm/contacts/link' && $method === 'POST') {
    header('Content-Type: application/json');
    try {
        crm_with(fn($crm) => $crm->linkContactToAccount(
            (string)($_POST['contact_id'] ?? ''),
            (string)($_POST['account_id'] ?? ''),
            $_POST['role'] ?? null,
            !empty($_POST['is_primary'])
        ));
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    return;
}

if (($contactId = crm_uuid_from_path($path, '/crm/contacts')) && $method === 'GET') {
    try {
        $contact = crm_with(fn($crm) => $crm->getContact($contactId));
        if (!$contact) {
            http_response_code(404);
            echo 'Contact not found';
            return;
        }
        $notes = crm_with(fn($crm) => $crm->listNotes('contact', $contactId));
        $activities = crm_with(fn($crm) => $crm->listActivities('contact', $contactId));
        $history = crm_with(fn($crm) => $crm->auditHistory('contact', $contactId));
        crm_page('CRM · Contact', 'crm-contacts', 'contact-detail.php', [
            'contact' => $contact, 'notes' => $notes, 'activities' => $activities, 'history' => $history,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Could not load contact.';
    }
    return;
}

// ------------------------------------------------------------------ activities
if ($path === '/crm/activities' && $method === 'GET') {
    crm_page('CRM · Activities', 'crm-activities', 'activities.php');
    return;
}

if ($path === '/crm/activities/list' && $method === 'GET') {
    $overdue = ($_GET['overdue'] ?? '') === '1';
    try {
        $acts = crm_with(fn($crm) => $crm->listActivities(
            $_GET['entity_type'] ?? null ?: null,
            $_GET['entity_id'] ?? null ?: null,
            $overdue
        ));
        crm_render_activity_list($acts);
    } catch (Throwable $e) {
        echo '<div class="text-sm text-red-400">Could not load activities.</div>';
    }
    return;
}

if ($path === '/crm/activities/create' && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        $links = [];
        if (!empty($_POST['entity_type']) && !empty($_POST['entity_id'])) {
            $links[] = ['entity_type' => $_POST['entity_type'], 'entity_id' => $_POST['entity_id']];
        }
        crm_with(fn($crm) => $crm->createActivity($_POST, $links));
        $acts = crm_with(fn($crm) => $crm->listActivities());
        crm_render_activity_list($acts);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo '<div class="text-sm text-red-400">' . crm_h($e->getMessage()) . '</div>';
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<div class="text-sm text-red-400">Could not create activity.</div>';
    }
    return;
}

if (preg_match('#^/crm/activities/([0-9a-f-]{36})/complete$#i', $path, $m) && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        crm_with(fn($crm) => $crm->completeActivity(strtolower($m[1])));
        $acts = crm_with(fn($crm) => $crm->listActivities());
        crm_render_activity_list($acts);
    } catch (Throwable $e) {
        http_response_code(400);
        echo '<div class="text-sm text-red-400">' . crm_h($e->getMessage()) . '</div>';
    }
    return;
}

// ------------------------------------------------------------------ notes
if ($path === '/crm/notes/list' && $method === 'GET') {
    try {
        $notes = crm_with(fn($crm) => $crm->listNotes(
            (string)($_GET['entity_type'] ?? ''),
            (string)($_GET['entity_id'] ?? '')
        ));
        crm_render_notes($notes);
    } catch (Throwable $e) {
        echo '<div class="text-sm text-red-400">Could not load notes.</div>';
    }
    return;
}

if ($path === '/crm/notes/create' && $method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        $et = (string)($_POST['entity_type'] ?? '');
        $eid = (string)($_POST['entity_id'] ?? '');
        crm_with(fn($crm) => $crm->addNote($et, $eid, (string)($_POST['body'] ?? '')));
        $notes = crm_with(fn($crm) => $crm->listNotes($et, $eid));
        crm_render_notes($notes);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo '<div class="text-sm text-red-400">' . crm_h($e->getMessage()) . '</div>';
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<div class="text-sm text-red-400">Could not save note.</div>';
    }
    return;
}

// ------------------------------------------------------------------ search
if ($path === '/crm/search' && $method === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    try {
        $results = crm_with(fn($crm) => $crm->search($q));
    } catch (Throwable $e) {
        $results = ['leads' => [], 'accounts' => [], 'contacts' => []];
    }
    $total = count($results['leads']) + count($results['accounts']) + count($results['contacts']);
    if ($total === 0) {
        echo '<div class="text-sm text-zinc-500">No matches.</div>';
        return;
    }
    foreach (['leads' => 'Leads', 'accounts' => 'Companies', 'contacts' => 'Contacts'] as $key => $label) {
        if (!$results[$key]) {
            continue;
        }
        echo '<div class="mb-3"><div class="text-xs font-semibold text-zinc-500 mb-1">' . $label . '</div><ul class="space-y-1">';
        foreach ($results[$key] as $r) {
            $name = $r['name'] ?? trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $url = '/crm/' . ($key === 'leads' ? 'leads' : ($key === 'accounts' ? 'accounts' : 'contacts')) . '/' . crm_h($r['id']);
            echo '<li class="text-sm"><a href="' . $url . '" class="hover:text-blue-300">' . crm_h($name) . '</a>';
            if (!empty($r['email'])) {
                echo ' <span class="text-xs text-zinc-500">' . crm_h($r['email']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul></div>';
    }
    return;
}

// No CRM route matched.
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found']);
