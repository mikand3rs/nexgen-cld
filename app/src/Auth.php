<?php
// BMOS 3.2 Stage A — authentication.
// PHP-native sessions (httponly, samesite). Tenant comes from verified
// membership, never from a client-supplied header.

class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Ensure a writable session path (php:8.4-fpm-alpine default may be unset).
            if (!ini_get('session.save_path')) {
                ini_set('session.save_path', '/tmp');
            }
            // Use ini_set instead of session_set_cookie_params (array syntax
            // was silently preventing session_start in this build).
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.cookie_path', '/');
            ini_set('session.cookie_lifetime', '0');
            // ini_set('session.cookie_secure', '1'); // enable with TLS
            session_start();
        }
    }

    public static function login(string $email, string $password): ?array {
        $pdo = Db::connect();
        // Use the SECURITY DEFINER function — direct SELECT is blocked by RLS
        // (we don't know who the user is yet during login).
        $stmt = $pdo->prepare("SELECT user_id AS id, pw_hash AS password_hash FROM app.find_user_for_login(:email)");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !$user['password_hash']) return null;
        if (!password_verify($password, $user['password_hash'])) return null;
        // Fetch the email separately (the function only returns id+hash).
        $stmt = $pdo->prepare("SELECT email FROM app.users WHERE id = :id");
        // Bypass RLS via the function again, or set context now that we know the user.
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL app.current_user_id = '{$user['id']}'");
        $stmt->execute([':id' => $user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        $userEmail = $row['email'] ?? $email;
        // Rehash if the algorithm upgraded.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $new = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE app.users SET password_hash = :h WHERE id = :id");
            $upd->execute([':h' => $new, ':id' => $user['id']]);
        }
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $userEmail;
        unset($_SESSION['tenant_id']); // force re-selection
        return ['id' => $user['id'], 'email' => $userEmail];
    }

    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
        }
        session_destroy();
    }

    public static function userId(): ?string {
        self::startSession();
        return $_SESSION['user_id'] ?? null;
    }

    public static function userEmail(): ?string {
        self::startSession();
        return $_SESSION['user_email'] ?? null;
    }

    /** Tenants this user is a member of (verified from DB). */
    public static function memberships(string $userId): array {
        $pdo = Db::connect();
        // SECURITY DEFINER function — RLS would block this before tenant context exists.
        $stmt = $pdo->prepare("SELECT tenant_id AS id, tenant_name AS name, role FROM app.get_user_memberships(:uid)");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The active tenant: must be one of the user's verified memberships.
     * Falls back to the first membership. Returns null if none.
     */
    public static function currentTenantId(): ?string {
        $uid = self::userId();
        if (!$uid) return null;
        $members = self::memberships($uid);
        if (!$members) return null;
        self::startSession();
        $selected = $_SESSION['tenant_id'] ?? null;
        foreach ($members as $m) {
            if ($m['id'] === $selected) return $selected;
        }
        // Default to first membership.
        $_SESSION['tenant_id'] = $members[0]['id'];
        return $members[0]['id'];
    }

    /** Switch active tenant — only to a verified membership. */
    public static function selectTenant(string $tenantId): bool {
        $uid = self::userId();
        if (!$uid) return false;
        foreach (self::memberships($uid) as $m) {
            if ($m['id'] === $tenantId) {
                self::startSession();
                $_SESSION['tenant_id'] = $tenantId;
                return true;
            }
        }
        return false;
    }

    /** Hash a password for seeding/admin creation. */
    public static function hash(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify an API key (for server-to-server, e.g. n8n).
     * Returns the tenant UUID, or null.
     */
    public static function verifyApiKey(string $key): ?string {
        $pdo = Db::connect();
        // Compare against stored hashes. (Small table; fine for now.)
        $rows = $pdo->query("SELECT id, tenant_id, key_hash FROM app.api_keys")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (password_verify($key, $r['key_hash'])) {
                $upd = $pdo->prepare("UPDATE app.api_keys SET last_used_at = now() WHERE id = :id");
                $upd->execute([':id' => $r['id']]);
                return $r['tenant_id'];
            }
        }
        return null;
    }

    /** Create an API key for a tenant. Returns the plaintext key (show once). */
    public static function createApiKey(string $tenantId, string $name): string {
        $key = 'bmos_' . bin2hex(random_bytes(32));
        $pdo = Db::connect();
        $stmt = $pdo->prepare("
            INSERT INTO app.api_keys (tenant_id, name, key_hash)
            VALUES (:tid, :name, :hash)
            ON CONFLICT (tenant_id, name) DO UPDATE SET key_hash = EXCLUDED.key_hash
            RETURNING id
        ");
        $stmt->execute([
            ':tid' => $tenantId,
            ':name' => $name,
            ':hash' => password_hash($key, PASSWORD_DEFAULT),
        ]);
        return $key;
    }

    /**
     * Guard for full-page UI routes. Redirects to /login if not authenticated.
     */
    public static function requireLogin(): void {
        if (!self::userId()) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Unified tenant resolution: session first, then Bearer API key.
     * This is what replaces X-Tenant-ID everywhere.
     */
    public static function requireTenant(): string {
        // 1. Browser session.
        $tid = self::currentTenantId();
        if ($tid) return $tid;
        // 2. API key via Authorization: Bearer bmos_...
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(bmos_[a-f0-9]{64})$/', $auth, $m)) {
            $tid = self::verifyApiKey($m[1]);
            if ($tid) return $tid;
        }
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'authentication required']);
        exit;
    }
}
