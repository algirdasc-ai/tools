<?php

/**
 * Subprocess wrapper for endpoints (which call exit()). Reads a JSON payload
 * file: {"script": "share.php", "post": {...}, "get": {...}}, sets up the
 * request environment, captures output + status, prints:
 *   STATUS <code>
 *   <raw body>
 */

$payload = json_decode(file_get_contents($argv[1]), true);

$_GET = $payload['get'] ?? [];
$_POST = $payload['post'] ?? [];
$_SERVER['SERVER_NAME'] = 'test.local';
$_SERVER['REQUEST_URI'] = '/' . $payload['script'];
$_SERVER['REQUEST_METHOD'] = empty($payload['post']) ? 'GET' : 'POST';

chdir(__DIR__ . '/..');

register_shutdown_function(function () {
    $body = ob_get_clean();
    $code = http_response_code();
    echo 'STATUS ' . ($code === false ? 200 : $code) . "\n";
    echo $body;
});

ob_start();
require($payload['script']);
