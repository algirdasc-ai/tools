<?php

/**
 * Integration test for the secrets feature. Run inside the app container:
 *
 *   docker compose exec tools php tests/secrets_test.php
 *
 * Requires the database to be up (uses the same MYSQL_* env vars as the app).
 * Exercises saveSecret / consumeSecret / fetchSecret end-to-end, including
 * burn-after-read and expiry behaviour. Cleans up all rows it creates.
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

require(__DIR__ . '/../config.php');
require(__DIR__ . '/../lib.php');

$failures = 0;

function check(string $name, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "PASS  $name\n";
    } else {
        echo "FAIL  $name\n";
        $failures++;
    }
}

function cleanup(string $hash): void
{
    deleteSecret($hash);
}

// --- saveSecret + fetchSecret ------------------------------------------------

$ciphertext = base64_encode(random_bytes(64));
$hash = null;

$secret = saveSecret($ciphertext, 3600, 1);
check('saveSecret returns a row', is_array($secret));
$hash = $secret['hash'] ?? '';
check('hash is non-empty', $hash !== '');
check('ciphertext round-trips', ($secret['ciphertext'] ?? null) === $ciphertext);
check('max_reads stored', (int) ($secret['max_reads'] ?? 0) === 1);
check('reads starts at 0', (int) ($secret['reads'] ?? -1) === 0);
check('expires_at is set', !empty($secret['expires_at']));

// Deduplication: same ciphertext -> same hash, no duplicate insert.
$again = saveSecret($ciphertext, 3600, 1);
check('duplicate ciphertext returns same hash', ($again['hash'] ?? '') === $hash);

// --- consumeSecret: burn after 1 read -----------------------------------------

$read1 = consumeSecret($hash);
check('first read returns ciphertext', $read1 === $ciphertext);

// max_reads=1 -> row is deleted immediately after the single allowed read.
check('burned secret is deleted', fetchSecret($hash) === null);

$read2 = consumeSecret($hash);
check('second read returns null (gone)', $read2 === null);

// --- multi-read secret ---------------------------------------------------------

$hash3 = (saveSecret(base64_encode(random_bytes(32)), 3600, 3))['hash'];
check('read 1 of 3 ok', consumeSecret($hash3) !== null);
check('read 2 of 3 ok', consumeSecret($hash3) !== null);
check('read 3 of 3 ok', consumeSecret($hash3) !== null);
check('read 4 of 3 burned', consumeSecret($hash3) === null);
check('row gone after burn', fetchSecret($hash3) === null);

// --- expiry ---------------------------------------------------------------------

$hash4 = (saveSecret(base64_encode(random_bytes(32)), 1, 5))['hash'];
// Force expiry by backdating.
mysql()->query("UPDATE secrets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE hash = '" . mysql()->real_escape_string($hash4) . "'");
check('expired secret reads as null', consumeSecret($hash4) === null);
check('expired row is deleted', fetchSecret($hash4) === null);

// --- unknown hash -----------------------------------------------------------------

check('unknown hash returns null', consumeSecret('doesnotexist') === null);

// --- defaults when ttl / max_reads are null ---------------------------------------

$secret5 = saveSecret(base64_encode(random_bytes(32)), null, null);
check('null ttl defaults to 4h expiry', !empty($secret5['expires_at']));
check('null max_reads defaults to 1', (int) $secret5['max_reads'] === 1);
cleanup($secret5['hash']);

// --- sweepExpiredSecrets -----------------------------------------------------------

$hash6 = (saveSecret(base64_encode(random_bytes(32)), 3600, 5))['hash'];
mysql()->query("UPDATE secrets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE hash = '" . mysql()->real_escape_string($hash6) . "'");
sweepExpiredSecrets();
check('sweep removes expired rows', fetchSecret($hash6) === null);

// ---------------------------------------------------------------------------

echo "\n" . ($failures === 0 ? 'ALL TESTS PASSED' : "$failures TEST(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
