<?php
// ---------------------------------------------------------------
// MathQuest API settings.
// TEMPLATE: copy to config.php and fill in the password and API key.
// ---------------------------------------------------------------

// Never print PHP warnings or SQL errors to the game or browser.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'mathquest');
// Least-privilege account (SELECT, INSERT, UPDATE on players only). Not root.
define('DB_USER', 'mathquest_app');
define('DB_PASS', 'PUT_PASSWORD_HERE');

// Must be the same text as "Api Key" on the ResultsUploader in Unity.
define('API_KEY', 'PUT_API_KEY_HERE');

// Server-side score rule. 175 = 10 correct x 15 + 5 crystals x 5 in our test run.
// Change these if the game's formula is different.
define('POINTS_PER_CORRECT', 15);
define('POINTS_PER_CRYSTAL', 5);

/** Writes a rejected request to the Apache error log (no secrets, no full body). */
function log_rejection(string $reason): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    error_log("[MathQuest][REJECTED] $reason ip=$ip");
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    exit;
}

function require_api_key(): void
{
    $sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($sent) || !hash_equals(API_KEY, $sent)) {
        log_rejection('wrong or missing API key');
        respond(401, ['ok' => false, 'error' => 'wrong or missing API key']);
    }
}

/** Reads a whole number from the request and checks it is in range. */
function int_field(array $in, string $key, int $min, int $max): int
{
    if (!isset($in[$key]) || !is_numeric($in[$key])) {
        log_rejection("missing $key");
        respond(422, ['ok' => false, 'error' => "missing $key"]);
    }
    $v = (int) $in[$key];
    if ($v < $min || $v > $max) {
        log_rejection("$key out of range");
        respond(422, ['ok' => false, 'error' => "$key out of range"]);
    }
    return $v;
}

function uuid_field(array $in, string $key): string
{
    $v = $in[$key] ?? '';
    if (!is_string($v) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
        respond(422, ['ok' => false, 'error' => "bad $key"]);
    }
    return strtolower($v);
}
