<?php

require('config.php');
require('lib.php');

header('Content-Type: application/json');

// Server stores ciphertext only; the encryption key never leaves the browser
// (it travels in the URL fragment, which is not sent to the server).

const MAX_SECRET_BYTES = 64 * 1024; // 64 KB of base64 ciphertext
const MAX_TTL_SECONDS = 7 * 24 * 3600; // 7 days
const MAX_READS_LIMIT = 100;

$ciphertext = trim($_POST['ciphertext'] ?? '');
$ttlSeconds = filter_input(INPUT_POST, 'ttl', FILTER_VALIDATE_INT);
$maxReads = filter_input(INPUT_POST, 'max_reads', FILTER_VALIDATE_INT);

if (
    !$ciphertext
    || strlen($ciphertext) > MAX_SECRET_BYTES
    || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $ciphertext)
) {
    error('Invalid request');
}

// Defaults: burn after 1 read, expire after 4 hours.
$ttlSeconds = ($ttlSeconds === false || $ttlSeconds === null || $ttlSeconds <= 0)
    ? 4 * 3600
    : min($ttlSeconds, MAX_TTL_SECONDS);

$maxReads = ($maxReads === false || $maxReads === null || $maxReads <= 0)
    ? 1
    : min($maxReads, MAX_READS_LIMIT);

// Opportunistic cleanup of expired secrets.
sweepExpiredSecrets();

if ($secret = saveSecret($ciphertext, $ttlSeconds, $maxReads)) {
    response(['hash' => $secret['hash']]);
}

error('Failed to save secret');
