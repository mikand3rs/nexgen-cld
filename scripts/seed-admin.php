#!/usr/bin/env php
<?php
// BMOS 3.2 Stage A — seed an admin user.
// Usage: ADMIN_EMAIL=you@example.com ADMIN_PASSWORD=secret php scripts/seed-admin.php
// The password is read from env, never from argv (no ps exposure).

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

$email = getenv('ADMIN_EMAIL') ?: 'admin@bmos.local';
$password = getenv('ADMIN_PASSWORD');
if (!$password) {
    fwrite(STDERR, "Set ADMIN_PASSWORD env var.\n");
    exit(1);
}

// Ensure the demo tenant exists.
$pdo = Db::connect();
$pdo->exec("
    INSERT INTO app.tenants (id, name) VALUES
    ('11111111-1111-1111-1111-111111111111', 'Demo Tenant')
    ON CONFLICT (id) DO NOTHING
");

// Create or update the user.
$userId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$stmt = $pdo->prepare("
    INSERT INTO app.users (id, email, password_hash)
    VALUES (:id, :email, :hash)
    ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash
    RETURNING id
");
$stmt->execute([
    ':id' => $userId,
    ':email' => $email,
    ':hash' => Auth::hash($password),
]);
$uid = $stmt->fetchColumn() ?: $userId;

// Membership as owner.
$pdo->prepare("
    INSERT INTO app.memberships (user_id, tenant_id, role)
    VALUES (:uid, '11111111-1111-1111-1111-111111111111', 'owner')
    ON CONFLICT (user_id, tenant_id) DO UPDATE SET role = 'owner'
")->execute([':uid' => $uid]);

echo "Admin user ready: {$email}\n";
echo "Sign in at http://bmos.localhost/login\n";
