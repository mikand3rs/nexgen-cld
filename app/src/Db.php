<?php
// BMOS 3.2 — shared PDO connector for app code (web + worker + CLI).
// The DB password comes from the secret file, never from chat, logs,
// or exception messages. Callers that touch tenant tables must set
// app.current_tenant on the connection (SET LOCAL inside a transaction).

final class Db
{
    public static function connect(): PDO
    {
        $pwFile = getenv('DB_PASSWORD_FILE') ?: '';
        $pw = ($pwFile !== '' && is_readable($pwFile))
            ? trim((string) file_get_contents($pwFile))
            : '';
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST') ?: 'postgres',
            getenv('DB_PORT') ?: '5432',
            getenv('DB_NAME') ?: 'bmos'
        );
        return new PDO($dsn, getenv('DB_USER') ?: 'bmos_app', $pw, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}
