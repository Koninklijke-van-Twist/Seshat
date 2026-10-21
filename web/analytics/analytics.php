<?php

/**
 * Includes/requires
 */
$loginSessionUser = '';
foreach ([
    dirname(__DIR__, 2) . '/login/session_user.php',
    dirname(__DIR__, 3) . '/login/session_user.php',
] as $loginSessionUserCandidate) {
    if (is_file($loginSessionUserCandidate)) {
        $loginSessionUser = $loginSessionUserCandidate;
        break;
    }
}
if ($loginSessionUser !== '') {
    require_once $loginSessionUser;
}

/**
 * Constants
 */

const ANALYTICS_DB_PATH = __DIR__ . '/analytics.sqlite';

/**
 * Functies
 */

function analytics_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function analytics_request_value(string $key): string
{
    return trim((string) ($_POST[$key] ?? $_GET[$key] ?? ''));
}

function analytics_request_api_key(): string
{
    $headerKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($headerKey !== '') {
        return $headerKey;
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                return trim((string) $value);
            }
        }
    }

    return analytics_request_value('api_key');
}

function analytics_authorize(string $email, string $apiKey, string $oid): bool
{
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if ($apiKey === '' || $oid === '') {
        return false;
    }

    return function_exists('verify_rotating_api_key') && verify_rotating_api_key($oid, $apiKey);
}

function analytics_ensure_htaccess(): void
{
    // Deploy slaat .htaccess over. De map schrijft de afscherming dus zelf,
    // in dezelfde trant als Kothar: analytics.php blijft het endpoint.
    $dir = __DIR__;
    $path = $dir . '/.htaccess';
    $rules = "Options -Indexes\n"
        . "# analytics.php blijft het endpoint. Alleen sqlite-bestanden zijn afgesloten.\n"
        . "<FilesMatch \"\\.sqlite\">\n"
        . "    <IfModule mod_authz_core.c>\n"
        . "        Require all denied\n"
        . "    </IfModule>\n"
        . "    <IfModule !mod_authz_core.c>\n"
        . "        Order allow,deny\n"
        . "        Deny from all\n"
        . "    </IfModule>\n"
        . "</FilesMatch>\n";

    $current = is_file($path) ? (string) @file_get_contents($path) : null;
    if ($current === null) {
        if (is_writable($dir)) {
            @file_put_contents($path, $rules, LOCK_EX);
        }

        return;
    }

    if (stripos($current, 'sqlite') === false && is_writable($path)) {
        @file_put_contents($path, rtrim($current) . "\n\n" . $rules, LOCK_EX);
    }
}

function analytics_ensure_db_writable(): void
{
    $dir = dirname(ANALYTICS_DB_PATH);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Analytics directory could not be created');
    }

    @chmod($dir, 0777);
    analytics_ensure_htaccess();

    if (!is_writable($dir)) {
        throw new RuntimeException('Analytics directory is not writable');
    }

    if (!is_file(ANALYTICS_DB_PATH)) {
        $created = @touch(ANALYTICS_DB_PATH);
        if ($created) {
            @chmod(ANALYTICS_DB_PATH, 0666);
        }
    } elseif (!is_writable(ANALYTICS_DB_PATH)) {
        @chmod(ANALYTICS_DB_PATH, 0666);
    }
}

function analytics_pdo(): PDO
{
    analytics_ensure_db_writable();
    $pdo = new PDO('sqlite:' . ANALYTICS_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visited_at INTEGER NOT NULL,
            user_email TEXT NOT NULL
        )'
    );
    analytics_migrate_visited_at_to_integer($pdo);

    return $pdo;
}

function analytics_migrate_visited_at_to_integer(PDO $pdo): void
{
    $columnType = '';
    foreach ($pdo->query('PRAGMA table_info(visits)') as $column) {
        if (strtolower((string) ($column['name'] ?? '')) === 'visited_at') {
            $columnType = strtoupper((string) ($column['type'] ?? ''));
            break;
        }
    }

    if ($columnType === 'INTEGER') {
        return;
    }

    $pdo->exec(
        'CREATE TABLE visits_integer (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visited_at INTEGER NOT NULL,
            user_email TEXT NOT NULL
        )'
    );

    $rows = $pdo->query('SELECT id, visited_at, user_email FROM visits')->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare(
        'INSERT INTO visits_integer (id, visited_at, user_email) VALUES (:id, :visited_at, :user_email)'
    );

    foreach ($rows as $row) {
        $raw = trim((string) ($row['visited_at'] ?? ''));
        $timestamp = ctype_digit($raw) ? (int) $raw : (int) strtotime($raw);
        $insert->execute([
            ':id' => (int) ($row['id'] ?? 0),
            ':visited_at' => $timestamp > 0 ? $timestamp : time(),
            ':user_email' => (string) ($row['user_email'] ?? ''),
        ]);
    }

    $pdo->exec('DROP TABLE visits');
    $pdo->exec('ALTER TABLE visits_integer RENAME TO visits');
}

function analytics_record_visit(string $email): void
{
    $email = strtolower(trim($email));
    $pdo = analytics_pdo();
    $recent = $pdo->prepare(
        'SELECT 1 FROM visits WHERE user_email = :user_email AND visited_at >= :since LIMIT 1'
    );
    $recent->execute([
        ':user_email' => $email,
        ':since' => time() - 60,
    ]);
    if ($recent->fetchColumn()) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO visits (visited_at, user_email) VALUES (:visited_at, :user_email)'
    );
    $statement->execute([
        ':visited_at' => time(),
        ':user_email' => $email,
    ]);
}

/**
 * Page load
 */

analytics_ensure_htaccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    analytics_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$email = strtolower(analytics_request_value('user_email'));
$apiKey = analytics_request_api_key();
$oid = strtolower(analytics_request_value('oid'));
if ($oid === '') {
    $oid = strtolower(trim((string) ($_SERVER['HTTP_X_OID'] ?? '')));
}

if (!analytics_authorize($email, $apiKey, $oid)) {
    analytics_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

try {
    analytics_record_visit($email);
    analytics_json(['ok' => true]);
} catch (Throwable $error) {
    analytics_json([
        'ok' => false,
        'error' => 'Visit could not be recorded',
        'detail' => $error->getMessage(),
    ], 500);
}
