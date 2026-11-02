<?php
// BMOS 3.2 — CRM 1.0: native customer & sales service.
// All CRM writes go through this class. The caller owns the transaction
// and MUST set app.current_tenant (SET LOCAL) before calling — RLS then
// enforces isolation on every query. Cross-tenant references are blocked
// by composite (tenant_id, id) foreign keys; polymorphic entity links are
// validated here against ENTITY_TYPES.

class Crm
{
    public const ENTITY_TYPES = ['lead', 'account', 'contact', 'opportunity'];
    public const LEAD_STATUSES = ['new', 'contacted', 'qualified', 'disqualified', 'converted'];
    public const ACTIVITY_TYPES = ['call', 'meeting', 'email', 'note', 'followup'];

    private PDO $pdo;
    private string $tenantId;
    private string $actor;

    public function __construct(PDO $pdo, string $tenantId, string $actor = 'system')
    {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId;
        $this->actor = $actor;
    }

    // ------------------------------------------------------------------ leads
    public function createLead(array $d): string
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('lead name is required');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.crm_leads (tenant_id, name, email, phone, company_name, source, status, notes)
             VALUES (:t, :name, :email, :phone, :company, :source, 'new', :notes)
             RETURNING id"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':name' => mb_substr($name, 0, 200),
            ':email' => $this->nullIfEmpty($d['email'] ?? null, 200),
            ':phone' => $this->nullIfEmpty($d['phone'] ?? null, 50),
            ':company' => $this->nullIfEmpty($d['company_name'] ?? null, 200),
            ':source' => $this->nullIfEmpty($d['source'] ?? null, 50),
            ':notes' => $this->nullIfEmpty($d['notes'] ?? null, 2000),
        ]);
        $id = (string)$stmt->fetchColumn();
        $this->audit('crm.lead.created', 'lead', $id, ['name' => $name]);
        return $id;
    }

    public function listLeads(?string $status = null, int $limit = 50): array
    {
        $sql = "SELECT id, name, email, phone, company_name, source, status, created_at
                FROM app.crm_leads WHERE 1=1";
        $params = [];
        if ($status !== null && $status !== '') {
            if (!in_array($status, self::LEAD_STATUSES, true)) {
                throw new InvalidArgumentException('invalid lead status');
            }
            $sql .= " AND status = :s";
            $params[':s'] = $status;
        }
        $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLead(string $id): ?array
    {
        $this->assertUuid($id);
        $stmt = $this->pdo->prepare("SELECT * FROM app.crm_leads WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateLeadStatus(string $id, string $status): void
    {
        $this->assertUuid($id);
        if (!in_array($status, self::LEAD_STATUSES, true)) {
            throw new InvalidArgumentException('invalid lead status');
        }
        $lead = $this->getLead($id);
        if (!$lead) {
            throw new RuntimeException('lead not found');
        }
        if ($lead['status'] === 'converted' && $status !== 'converted') {
            throw new RuntimeException('converted leads cannot be reopened');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE app.crm_leads SET status = :s, updated_at = now() WHERE id = :id"
        );
        $stmt->execute([':s' => $status, ':id' => $id]);
        $this->audit('crm.lead.status', 'lead', $id, ['from' => $lead['status'], 'to' => $status]);
    }

    /**
     * Qualify a lead: transactionally create account + contact (+ optional
     * opportunity), link them, and mark the lead converted. The lead and its
     * history are preserved, never deleted.
     */
    public function convertLead(string $id, array $opts = []): array
    {
        $this->assertUuid($id);
        $lead = $this->getLead($id);
        if (!$lead) {
            throw new RuntimeException('lead not found');
        }
        if ($lead['status'] === 'converted') {
            throw new RuntimeException('lead already converted');
        }

        $accountName = trim((string)($opts['account_name'] ?? $lead['company_name'] ?? $lead['name']));
        if ($accountName === '') {
            throw new InvalidArgumentException('account name is required for conversion');
        }
        $accountId = $this->createAccount([
            'name' => $accountName,
            'email' => $lead['email'],
            'phone' => $lead['phone'],
            'status' => 'customer',
        ]);

        // Split lead name into first/last on the first space.
        $parts = preg_split('/\s+/', trim($lead['name']), 2);
        $contactId = $this->createContact([
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? null,
            'email' => $lead['email'],
            'phone' => $lead['phone'],
        ]);
        $this->linkContactToAccount($contactId, $accountId, null, true);

        $opportunityId = null;
        if (!empty($opts['opportunity_name'])) {
            $opportunityId = $this->createOpportunity([
                'name' => $opts['opportunity_name'],
                'account_id' => $accountId,
                'contact_id' => $contactId,
                'value_cents' => (int)($opts['value_cents'] ?? 0),
            ]);
        }

        $stmt = $this->pdo->prepare(
            "UPDATE app.crm_leads
             SET status = 'converted', converted_at = now(),
                 converted_account_id = :a, converted_contact_id = :c, updated_at = now()
             WHERE id = :id"
        );
        $stmt->execute([':a' => $accountId, ':c' => $contactId, ':id' => $id]);

        $this->audit('crm.lead.converted', 'lead', $id, [
            'account_id' => $accountId,
            'contact_id' => $contactId,
            'opportunity_id' => $opportunityId,
        ]);
        return ['account_id' => $accountId, 'contact_id' => $contactId, 'opportunity_id' => $opportunityId];
    }

    // ------------------------------------------------------------------ accounts
    public function createAccount(array $d): string
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('account name is required');
        }
        $status = (string)($d['status'] ?? 'prospect');
        if (!in_array($status, ['prospect', 'customer', 'former'], true)) {
            throw new InvalidArgumentException('invalid account status');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.crm_accounts (tenant_id, name, website, phone, email, address, industry, status)
             VALUES (:t, :name, :website, :phone, :email, :address, :industry, :status)
             RETURNING id"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':name' => mb_substr($name, 0, 200),
            ':website' => $this->nullIfEmpty($d['website'] ?? null, 200),
            ':phone' => $this->nullIfEmpty($d['phone'] ?? null, 50),
            ':email' => $this->nullIfEmpty($d['email'] ?? null, 200),
            ':address' => $this->nullIfEmpty($d['address'] ?? null, 500),
            ':industry' => $this->nullIfEmpty($d['industry'] ?? null, 100),
            ':status' => $status,
        ]);
        $id = (string)$stmt->fetchColumn();
        $this->audit('crm.account.created', 'account', $id, ['name' => $name]);
        return $id;
    }

    public function listAccounts(?string $q = null, int $limit = 50): array
    {
        $sql = "SELECT id, name, email, phone, industry, status, created_at FROM app.crm_accounts";
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= " WHERE name ILIKE :q OR email ILIKE :q";
            $params[':q'] = '%' . trim($q) . '%';
        }
        $sql .= " ORDER BY name ASC LIMIT " . (int)$limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAccount(string $id): ?array
    {
        $this->assertUuid($id);
        $stmt = $this->pdo->prepare("SELECT * FROM app.crm_accounts WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Linked contacts.
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.first_name, c.last_name, c.email, c.title, ac.role, ac.is_primary
             FROM app.crm_account_contacts ac
             JOIN app.crm_contacts c ON c.tenant_id = ac.tenant_id AND c.id = ac.contact_id
             WHERE ac.account_id = :id ORDER BY ac.is_primary DESC, c.last_name"
        );
        $stmt->execute([':id' => $id]);
        $row['contacts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    // ------------------------------------------------------------------ contacts
    public function createContact(array $d): string
    {
        $first = trim((string)($d['first_name'] ?? ''));
        if ($first === '') {
            throw new InvalidArgumentException('contact first name is required');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.crm_contacts (tenant_id, first_name, last_name, email, phone, title)
             VALUES (:t, :first, :last, :email, :phone, :title)
             RETURNING id"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':first' => mb_substr($first, 0, 100),
            ':last' => $this->nullIfEmpty($d['last_name'] ?? null, 100),
            ':email' => $this->nullIfEmpty($d['email'] ?? null, 200),
            ':phone' => $this->nullIfEmpty($d['phone'] ?? null, 50),
            ':title' => $this->nullIfEmpty($d['title'] ?? null, 100),
        ]);
        $id = (string)$stmt->fetchColumn();
        $this->audit('crm.contact.created', 'contact', $id, ['name' => $first]);
        return $id;
    }

    public function listContacts(?string $q = null, int $limit = 50): array
    {
        $sql = "SELECT id, first_name, last_name, email, phone, title, created_at FROM app.crm_contacts";
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $sql .= " WHERE first_name ILIKE :q OR last_name ILIKE :q OR email ILIKE :q";
            $params[':q'] = '%' . trim($q) . '%';
        }
        $sql .= " ORDER BY last_name ASC, first_name ASC LIMIT " . (int)$limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getContact(string $id): ?array
    {
        $this->assertUuid($id);
        $stmt = $this->pdo->prepare("SELECT * FROM app.crm_contacts WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.name, ac.role, ac.is_primary
             FROM app.crm_account_contacts ac
             JOIN app.crm_accounts a ON a.tenant_id = ac.tenant_id AND a.id = ac.account_id
             WHERE ac.contact_id = :id ORDER BY a.name"
        );
        $stmt->execute([':id' => $id]);
        $row['accounts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function linkContactToAccount(string $contactId, string $accountId, ?string $role, bool $primary = false): void
    {
        $this->assertUuid($contactId);
        $this->assertUuid($accountId);
        // Existence checks are tenant-scoped by RLS; the composite FK then
        // guarantees both rows belong to THIS tenant.
        if (!$this->getContact($contactId)) {
            throw new RuntimeException('contact not found');
        }
        $account = $this->getAccount($accountId);
        if (!$account) {
            throw new RuntimeException('account not found');
        }
        if ($primary) {
            $stmt = $this->pdo->prepare(
                "UPDATE app.crm_account_contacts SET is_primary = false WHERE account_id = :a"
            );
            $stmt->execute([':a' => $accountId]);
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.crm_account_contacts (tenant_id, account_id, contact_id, role, is_primary)
             VALUES (:t, :a, :c, :role, :p)
             ON CONFLICT (tenant_id, account_id, contact_id)
             DO UPDATE SET role = EXCLUDED.role, is_primary = EXCLUDED.is_primary"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':a' => $accountId,
            ':c' => $contactId,
            ':role' => $this->nullIfEmpty($role, 100),
            ':p' => $primary ? 'true' : 'false',
        ]);
    }

    // ------------------------------------------------------------------ opportunities (1.1 schema, minimal 1.0 use)
    public function createOpportunity(array $d): string
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('opportunity name is required');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.crm_opportunities (tenant_id, account_id, contact_id, name, value_cents)
             VALUES (:t, :a, :c, :name, :v) RETURNING id"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':a' => $this->nullIfEmpty($d['account_id'] ?? null),
            ':c' => $this->nullIfEmpty($d['contact_id'] ?? null),
            ':name' => mb_substr($name, 0, 200),
            ':v' => max(0, (int)($d['value_cents'] ?? 0)),
        ]);
        return (string)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------------ notes
    public function addNote(string $entityType, string $entityId, string $body): string
    {
        $this->assertEntityType($entityType);
        $this->assertUuid($entityId);
        $this->assertEntity($entityType, $entityId);
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('note body is required');
        }
        // Never store secrets in notes: refuse obvious credential material.
        if (preg_match('/\b(password|passwd|secret|api[_-]?key)\s*[:=]/i', $body)) {
            throw new InvalidArgumentException('notes must not contain passwords or API keys');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.crm_notes (tenant_id, entity_type, entity_id, body, created_by)
             VALUES (:t, :et, :eid, :body, :by) RETURNING id"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':et' => $entityType,
            ':eid' => $entityId,
            ':body' => mb_substr($body, 0, 5000),
            ':by' => mb_substr($this->actor, 0, 200),
        ]);
        return (string)$stmt->fetchColumn();
    }

    public function listNotes(string $entityType, string $entityId, int $limit = 50): array
    {
        $this->assertEntityType($entityType);
        $this->assertUuid($entityId);
        $stmt = $this->pdo->prepare(
            "SELECT id, body, created_by, created_at FROM app.crm_notes
             WHERE entity_type = :et AND entity_id = :eid
             ORDER BY created_at DESC LIMIT " . (int)$limit
        );
        $stmt->execute([':et' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------ activities
    public function createActivity(array $d, array $links = []): string
    {
        $type = (string)($d['type'] ?? '');
        if (!in_array($type, self::ACTIVITY_TYPES, true)) {
            throw new InvalidArgumentException('invalid activity type');
        }
        $subject = trim((string)($d['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('activity subject is required');
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.crm_activities (tenant_id, type, subject, body, due_at, created_by)
             VALUES (:t, :type, :subject, :body, :due, :by) RETURNING id"
        );
        $dueAt = $this->nullIfEmpty($d['due_at'] ?? null);
        $stmt->execute([
            ':t' => $this->tenantId,
            ':type' => $type,
            ':subject' => mb_substr($subject, 0, 200),
            ':body' => $this->nullIfEmpty($d['body'] ?? null, 2000),
            ':due' => ($dueAt !== null && strtotime($dueAt) !== false) ? date('c', strtotime($dueAt)) : null,
            ':by' => mb_substr($this->actor, 0, 200),
        ]);
        $id = (string)$stmt->fetchColumn();
        foreach ($links as $link) {
            $et = (string)($link['entity_type'] ?? '');
            $eid = (string)($link['entity_id'] ?? '');
            $this->assertEntityType($et);
            $this->assertUuid($eid);
            $this->assertEntity($et, $eid);
            $stmt = $this->pdo->prepare(
                "INSERT INTO app.crm_activity_links (tenant_id, activity_id, entity_type, entity_id)
                 VALUES (:t, :a, :et, :eid) ON CONFLICT DO NOTHING"
            );
            $stmt->execute([':t' => $this->tenantId, ':a' => $id, ':et' => $et, ':eid' => $eid]);
        }
        return $id;
    }

    public function listActivities(?string $entityType = null, ?string $entityId = null, bool $overdueOnly = false, int $limit = 50): array
    {
        $params = [];
        if ($entityType !== null) {
            $this->assertEntityType($entityType);
            $this->assertUuid((string)$entityId);
            $from = "app.crm_activity_links l JOIN app.crm_activities a
                     ON a.tenant_id = l.tenant_id AND a.id = l.activity_id";
            $where = "l.entity_type = :et AND l.entity_id = :eid";
            $params = [':et' => $entityType, ':eid' => $entityId];
            $p = 'a.';
        } else {
            $from = "app.crm_activities";
            $where = "1=1";
            $p = '';
        }
        $cols = [];
        foreach (['id','type','subject','body','due_at','completed_at','created_by','created_at'] as $c) {
            $cols[] = $p . $c;
        }
        $sql = "SELECT " . implode(', ', $cols) . " FROM {$from} WHERE {$where}";
        if ($overdueOnly) {
            $sql .= " AND {$p}completed_at IS NULL AND {$p}due_at < now()";
        }
        $sql .= " ORDER BY " . ($overdueOnly ? "{$p}due_at ASC" : "{$p}created_at DESC");
        $sql .= " LIMIT " . (int)$limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function completeActivity(string $id): void
    {
        $this->assertUuid($id);
        $stmt = $this->pdo->prepare(
            "UPDATE app.crm_activities SET completed_at = now() WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('activity not found');
        }
    }

    // ------------------------------------------------------------------ search & stats
    public function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['leads' => [], 'accounts' => [], 'contacts' => []];
        }
        $like = '%' . $q . '%';
        $out = [];
        $stmt = $this->pdo->prepare(
            "SELECT id, name, email, company_name, status FROM app.crm_leads
             WHERE name ILIKE :q OR email ILIKE :q OR company_name ILIKE :q
             ORDER BY created_at DESC LIMIT " . (int)$limit
        );
        $stmt->execute([':q' => $like]);
        $out['leads'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare(
            "SELECT id, name, email, industry, status FROM app.crm_accounts
             WHERE name ILIKE :q OR email ILIKE :q ORDER BY name LIMIT " . (int)$limit
        );
        $stmt->execute([':q' => $like]);
        $out['accounts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare(
            "SELECT id, first_name, last_name, email, title FROM app.crm_contacts
             WHERE first_name ILIKE :q OR last_name ILIKE :q OR email ILIKE :q
             ORDER BY last_name LIMIT " . (int)$limit
        );
        $stmt->execute([':q' => $like]);
        $out['contacts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $out;
    }

    public function stats(): array
    {
        $s = [];
        $s['open_leads'] = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM app.crm_leads WHERE status IN ('new','contacted','qualified')"
        )->fetchColumn();
        $s['accounts'] = (int)$this->pdo->query("SELECT COUNT(*) FROM app.crm_accounts")->fetchColumn();
        $s['contacts'] = (int)$this->pdo->query("SELECT COUNT(*) FROM app.crm_contacts")->fetchColumn();
        $s['overdue_followups'] = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM app.crm_activities
             WHERE completed_at IS NULL AND due_at < now()"
        )->fetchColumn();
        $s['activities_due_week'] = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM app.crm_activities
             WHERE completed_at IS NULL AND due_at >= now() AND due_at < now() + interval '7 days'"
        )->fetchColumn();
        return $s;
    }

    // ------------------------------------------------------------------ internals
    private function audit(string $action, string $entityType, ?string $entityId, array $details): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO app.audit_events (tenant_id, actor, action, entity_type, entity_id, details)
             VALUES (:t, :actor, :action, :et, :eid, :details)"
        );
        $stmt->execute([
            ':t' => $this->tenantId,
            ':actor' => mb_substr($this->actor, 0, 200),
            ':action' => $action,
            ':et' => $entityType,
            ':eid' => $entityId,
            ':details' => json_encode($details),
        ]);
    }

    public function auditHistory(string $entityType, string $entityId, int $limit = 50): array
    {
        $this->assertEntityType($entityType);
        $this->assertUuid($entityId);
        $stmt = $this->pdo->prepare(
            "SELECT actor, action, details, created_at FROM app.audit_events
             WHERE entity_type = :et AND entity_id = :eid
             ORDER BY created_at DESC LIMIT " . (int)$limit
        );
        $stmt->execute([':et' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertEntityType(string $t): void
    {
        if (!in_array($t, self::ENTITY_TYPES, true)) {
            throw new InvalidArgumentException('invalid entity type');
        }
    }

    private function assertUuid(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            throw new InvalidArgumentException('invalid id');
        }
    }

    /** Verify a polymorphic target exists in THIS tenant (RLS-scoped). */
    private function assertEntity(string $type, string $id): void
    {
        $table = [
            'lead' => 'app.crm_leads',
            'account' => 'app.crm_accounts',
            'contact' => 'app.crm_contacts',
            'opportunity' => 'app.crm_opportunities',
        ][$type];
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException("{$type} not found");
        }
    }

    private function nullIfEmpty($v, ?int $max = null)
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        return $max !== null ? mb_substr($v, 0, $max) : $v;
    }
}
