<?php

/**
 * Tests for gzinflate.php, gzuncompress.php, autoformat.php endpoints.
 */

require(__DIR__ . '/bootstrap.php');

$_SERVER['SERVER_NAME'] = 'test.local';
require(__DIR__ . '/../config.php');
require(__DIR__ . '/../lib.php');

echo "== gzinflate.php ==\n";

// --- happy path: base64(deflate(text)) ---------------------------------------------------

$plain = 'hello inflate world';
$deflated = gzdeflate($plain);
$b64 = base64_encode($deflated);

$r = http_subprocess('gzinflate.php', ['base64' => $b64]);
eq('inflate status', 200, $r['status']);
eq('inflate data', $plain, $r['json']['data'] ?? '');
check('inflate returns redirect', !empty($r['json']['redirect']));

// --- hex input ------------------------------------------------------------------------------

$r = http_subprocess('gzinflate.php', ['base64' => bin2hex($deflated)]);
eq('inflate hex data', $plain, $r['json']['data'] ?? '');

// --- JSON detection -> pretty-printed --------------------------------------------------------

$json = gzdeflate('{"a":1,"b":[2,3]}');
$r = http_subprocess('gzinflate.php', ['base64' => base64_encode($json)]);
check('json detected + indented', strpos($r['json']['data'] ?? '', "\n") !== false);

// --- XML detection ----------------------------------------------------------------------------

$xml = gzdeflate('<?xml version="1.0"?><root><a>1</a></root>');
$r = http_subprocess('gzinflate.php', ['base64' => base64_encode($xml)]);
check('xml detected', !empty($r['json']['data']));

// --- errors -------------------------------------------------------------------------------------

$r = http_subprocess('gzinflate.php', []);
eq('inflate empty 400', 400, $r['status']);

$r = http_subprocess('gzinflate.php', ['base64' => 'not-compressed-data-at-all!!!']);
eq('inflate garbage 400', 400, $r['status']);

// --- decompression bomb guard: highly compressible data exceeding the cap -------------------------
// 20 MB of zeros compresses to ~20 KB — inflates past the 8 MB cap.
$bomb = base64_encode(gzdeflate(str_repeat("\0", 20 * 1024 * 1024)));
$r = http_subprocess('gzinflate.php', ['base64' => $bomb]);
eq('inflate bomb rejected', 400, $r['status']);

echo "\n== gzuncompress.php ==\n";

// --- happy path -----------------------------------------------------------------------------------

$comp = gzcompress($plain);
$r = http_subprocess('gzuncompress.php', ['base64' => base64_encode($comp)]);
eq('uncompress status', 200, $r['status']);
eq('uncompress data', $plain, $r['json']['data'] ?? '');

// --- hex -------------------------------------------------------------------------------------------

$r = http_subprocess('gzuncompress.php', ['base64' => bin2hex($comp)]);
eq('uncompress hex data', $plain, $r['json']['data'] ?? '');

// --- errors -----------------------------------------------------------------------------------------

$r = http_subprocess('gzuncompress.php', []);
eq('uncompress empty 400', 400, $r['status']);

$r = http_subprocess('gzuncompress.php', ['base64' => 'zzz-not-valid']);
eq('uncompress garbage 400', 400, $r['status']);

// --- bomb guard (this endpoint previously had NO cap — audit #3) --------------------------------------

$bomb = base64_encode(gzcompress(str_repeat("\0", 20 * 1024 * 1024)));
$r = http_subprocess('gzuncompress.php', ['base64' => $bomb]);
eq('uncompress bomb rejected', 400, $r['status']);

// Odd-length hex must not warn/fatal (regression).
$r = http_subprocess('gzuncompress.php', ['base64' => 'abc']);
check('odd hex no warning leak', strpos($r['raw'], 'Warning') === false && strpos($r['raw'], 'Fatal') === false);

echo "\n== autoformat.php ==\n";

// --- json indent (self-contained, no external service) --------------------------------------------------

$r = http_subprocess('autoformat.php', ['snippet' => '{"a":1,"b":[1,2,3]}', 'format' => 'json']);
eq('autoformat json status', 200, $r['status']);
check('autoformat json pretty', strpos($r['json']['snippet'] ?? '', "\n") !== false);

// --- xml indent (DOMDocument, local) ----------------------------------------------------------------------

$r = http_subprocess('autoformat.php', ['snippet' => '<?xml version="1.0"?><root><a>1</a></root>', 'format' => 'xml']);
eq('autoformat xml status', 200, $r['status']);
check('autoformat xml pretty', strpos($r['json']['snippet'] ?? '', "\n") !== false);

// --- XXE attempt must not resolve entities -----------------------------------------------------------------

$xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r>&x;</r>';
$r = http_subprocess('autoformat.php', ['snippet' => $xxe, 'format' => 'xml']);
check('XXE not resolved (no root:)', strpos($r['json']['snippet'] ?? '', 'root:') === false);
check('XXE no warning leak', strpos($r['raw'], 'Warning') === false);

// --- validation ----------------------------------------------------------------------------------------------

$r = http_subprocess('autoformat.php', []);
eq('autoformat empty 400', 400, $r['status']);

$r = http_subprocess('autoformat.php', ['snippet' => 'x', 'format' => '../../etc/passwd']);
eq('autoformat path traversal 400', 400, $r['status']);

$r = http_subprocess('autoformat.php', ['snippet' => 'x', 'format' => 'nonexistent']);
eq('autoformat unknown format 400', 400, $r['status']);

test_summary('tools');
exit($GLOBALS['failures'] === 0 ? 0 : 1);
