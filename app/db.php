<?php
declare(strict_types=1);

/** Lazily created PDO connection. */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $base = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
        try {
            $pdo = new PDO($base . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            if ((int) $e->getCode() !== 1049) { throw $e; }          // 1049 = unknown database
            $tmp = new PDO($base, DB_USER, DB_PASS, $opts);
            $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = new PDO($base . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $opts);
        }
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

/** Replace {table} placeholders with suffixed, quoted table names. */
function sqlt(string $sql): string {
    return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => '`' . $m[1] . TABLE_SUFFIX . '`', $sql);
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare(sqlt($sql));
    $st->execute($params);
    return $st;
}
function all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function one(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }
/** Run a write; returns affected rows. */
function run(string $sql, array $params = []): int { return q($sql, $params)->rowCount(); }
function insert(string $sql, array $params = []): int { q($sql, $params); return (int) db()->lastInsertId(); }

/** Transaction wrapper; nested calls join the outer transaction. */
function tx(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) { return $fn(); }
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
}

/** A message safe to show the user. Anything else is logged and shown generically. */
class UserError extends RuntimeException {}
