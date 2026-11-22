<?php

require('config.php');
require('lib.php');

header('Content-Type: application/json');

// POST (not GET): reading is a mutation - each successful read burns the
// secret one step closer to deletion.

$hash = trim($_POST['hash'] ?? '');

if (!$hash || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $hash)) {
    error('Invalid request');
}

$ciphertext = consumeSecret($hash);

if ($ciphertext === null) {
    http_response_code(410); // Gone: unknown, expired, or already burned.
    echo json_encode(['error' => 'Secret is gone. It was already read, expired, or never existed.']);
    exit();
}

response(['ciphertext' => $ciphertext]);
