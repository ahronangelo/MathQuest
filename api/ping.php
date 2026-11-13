<?php
// Open this in a browser to check that PHP can reach the database:
// http://localhost/mathquest/api/ping.php
// SECURITY: no record counts and no error details are shown to the public.
require __DIR__ . '/config.php';

try {
    db()->query('SELECT 1');
    respond(200, ['ok' => true]);
} catch (Throwable $e) {
    error_log('MathQuest ping: ' . $e->getMessage());   // details stay in the Apache log
    respond(503, ['ok' => false, 'error' => 'server unavailable']);
}
