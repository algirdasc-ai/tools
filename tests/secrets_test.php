<?php

/**
 * Integration tests for the secrets feature (lib + endpoints).
 * Requires the MySQL container. Cleans up all rows it creates.
 */

require(__DIR__ . '/bootstrap.php');

$_SERVER['SERVER_NAME'] = 'test.local';
require(__DIR__ . '/../config.php');
require(__DIR__ . '/../lib.php');

echo "== secrets: lib integration ==\n";

// --- saveSecret + fetchSecret -------------------------------------------------------

$ciphertext = base64_encode(random_bytes(64));

$secret = saveSecret($ciphertext, 3600, 1);
check('saveSecret returns a row', is_array($secret));
$hash = $secret['hash'] ?? '';
check('hash non-empty', $hash !== '');
eq('ciphertext round-trips', $ciphertext, $secret['ciphertext'] ?? null);
eq('max_reads stored', 1, (int) ($secret['max_reads'] ?? 0));
eq('reads starts at 0', 0, (int) ($secret['reads'] ?? -1));
check('expires_at set', !empty($secret['expires_at']));

$again = saveSecret($ciphertext, 3600, 1);
eq('duplicate ciphertext -> same hash', $hash, $again['hash'] ?? '');

// --- single-read burn ------------------------------------------------------------------

eq('first read returns ciphertext', $ciphertext, consumeSecret($hash));
check('burned secret deleted', fetchSecret($hash) === null);
check('second read null', consumeSecret($hash) === null);

// --- multi-read burn ---------------------------------------------------------------------

$hash3 = (saveSecret(base64_encode(random_bytes(32)), 3600, 3))['hash'];
check('read 1 of 3', consumeSecret($hash3) !== null);
check('read 2 of 3', consumeSecret($hash3) !== null);
check('read 3 of 3', consumeSecret($hash3) !== null);
check('read 4 burned', consumeSecret($hash3) === null);
check('row gone', fetchSecret($hash3) === null);

// --- expiry -------------------------------------------------------------------------------

$hash4 = (saveSecret(base64_encode(random_bytes(32)), 1, 5))['hash'];
mysql()->query("UPDATE secrets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE hash = '" . mysql()->real_escape_string($hash4) . "'");
check('expired reads null', consumeSecret($hash4) === null);
check('expired row deleted', fetchSecret($hash4) === null);

// --- unknown hash ----------------------------------------------------------------------------

check('unknown hash null', consumeSecret('doesnotexist') === null);

// --- null defaults ----------------------------------------------------------------------------

$s5 = saveSecret(base64_encode(random_bytes(32)), null, null);
check('null ttl -> expiry set', !empty($s5['expires_at']));
eq('null max_reads -> 1', 1, (int) $s5['max_reads']);
deleteSecret($s5['hash']);

// --- sweep --------------------------------------------------------------------------------------

$hash6 = (saveSecret(base64_encode(random_bytes(32)), 3600, 5))['hash'];
mysql()->query("UPDATE secrets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE hash = '" . mysql()->real_escape_string($hash6) . "'");
sweepExpiredSecrets();
check('sweep removes expired', fetchSecret($hash6) === null);

// --- large ciphertext ----------------------------------------------------------------------------

$big = base64_encode(random_bytes(60000)); // ~80KB base64 — under the 64KB POST cap? No: 80000 chars. Use 40k bytes.
$big = base64_encode(random_bytes(40000));
$bigSecret = saveSecret($big, 3600, 1);
check('large ciphertext saves', is_array($bigSecret));
eq('large ciphertext round-trips', $big, consumeSecret($bigSecret['hash']));

echo "\n== secrets: HTTP endpoints ==\n";

// --- secret_save.php ------------------------------------------------------------------------------

$r = http_subprocess('secret_save.php', ['ciphertext' => base64_encode('e2e-test-payload'), 'ttl' => '14400', 'max_reads' => '1']);
check('save returns hash', !empty($r['json']['hash']));
eq('save status 200', 200, $r['status']);
$httpHash = $r['json']['hash'];

$r = http_subprocess('secret_save.php', []);
eq('save empty rejected status', 400, $r['status']);
check('save empty rejected body', !empty($r['json']['error']));

$r = http_subprocess('secret_save.php', ['ciphertext' => '!!!not-base64!!!']);
eq('save invalid b64 rejected', 400, $r['status']);

$r = http_subprocess('secret_save.php', ['ciphertext' => str_repeat('A', 70000)]);
eq('save oversized rejected', 400, $r['status']);

// --- secret_read.php --------------------------------------------------------------------------------

$r = http_subprocess('secret_read.php', ['hash' => $httpHash]);
eq('read status 200', 200, $r['status']);
eq('read returns ciphertext', base64_encode('e2e-test-payload'), $r['json']['ciphertext'] ?? '');

$r = http_subprocess('secret_read.php', ['hash' => $httpHash]);
eq('second read 410', 410, $r['status']);
check('second read error msg', !empty($r['json']['error']));

$r = http_subprocess('secret_read.php', ['hash' => 'neverexisted']);
eq('unknown hash 410', 410, $r['status']);

$r = http_subprocess('secret_read.php', ['hash' => '"><script>alert(1)</script>']);
eq('injection hash 400', 400, $r['status']);

$r = http_subprocess('secret_read.php', []);
eq('empty hash 400', 400, $r['status']);

test_summary('secrets');
exit($GLOBALS['failures'] === 0 ? 0 : 1);
