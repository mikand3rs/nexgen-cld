<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Database connection
|--------------------------------------------------------------------------
|
| One PDO per request / worker run. Environment variables come from
| Compose (.env) or the container environment — never from code.
|
| Tenant rule: every query in app/lib/*.php filters tenant_id = ?.
| RLS (see schema.sql) is defense-in-depth for a future non-owner
| app role; the owner role used locally bypasses RLS in PostgreSQL,
| so the tenant filter in code is the real enforcement. Do not
| remove it from any query.
*/

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'postgres';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'nexgen';
    $user = getenv('DB_USER') ?: 'nexgen';
    $password = getenv('DB_PASSWORD') ?: '';

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

/**
 * Set the current tenant for this transaction (RLS setting).
 * Call inside a transaction, before any tenant query.
 */
function db_set_tenant(PDO $pdo, int $tenantId): void
{
    $stmt = $pdo->prepare("SELECT set_config('app.current_tenant', ?, true)");
    $stmt->execute([(string) $tenantId]);
}

/**
 * Resolve a tenant slug to its id, or throw. No silent default
 * tenant in code paths that read documents — the caller must pass
 * a slug, and upload.php defaults it to 'nexgen-cloud' explicitly
 * at the form level, not hidden in this function.
 */
function tenant_id_by_slug(PDO $pdo, string $slug): int
{
    $stmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();

    if (!is_array($row) || !isset($row['id'])) {
        throw new RuntimeException('Unknown tenant: ' . $slug);
    }

    return (int) $row['id'];
}

/**
 * Storage root for uploaded files. This is OUTSIDE the public web
 * root (app/public), so uploaded files are never web-accessible
 * and never executed. app/storage/uploads/<tenant_id>/<doc_id>/
 */
function storage_root(): string
{
    // app/lib/db.php -> app/ is one level up from lib/
    return dirname(__DIR__) . '/storage/uploads';
}
