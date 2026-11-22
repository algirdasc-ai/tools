<?php

/**
 * Integration tests for snippets (share.php, getSnippet, saveSnippet).
 */

require(__DIR__ . '/bootstrap.php');

$_SERVER['SERVER_NAME'] = 'test.local';
require(__DIR__ . '/../config.php');
require(__DIR__ . '/../lib.php');

echo "== snippets: lib ==\n";

$left = '{"hello":"world","xss":"<script>alert(1)</script>"}';
$right = '{"hello":"world2"}';

// --- saveSnippet / getSnippet ---------------------------------------------------------

$snippet = saveSnippet('json', $left);
check('saveSnippet returns row', is_array($snippet));
$hash = $snippet['hash'] ?? '';
check('hash non-empty', $hash !== '');
eq('format stored', 'json', $snippet['format'] ?? '');

// Full round-trip: htmlSafeSnippet + html_entity_decode (the browser-side
// decoding step is entity decoding — htmlSafeSnippet keeps entities by design).
eq('snippetLeft round-trip', $left, html_entity_decode(htmlSafeSnippet($snippet['snippetLeft'])));
check('no snippetRight when not given', empty($snippet['snippetRight']));

// Two-sided snippet.
$diff = saveSnippet('json', $left, $right);
eq('right side stored', $right, html_entity_decode(htmlSafeSnippet($diff['snippetRight'])));

// Same on both sides -> right nulled.
$same = saveSnippet('json', $left, $left);
check('identical right side nulled', empty($same['snippetRight']));

// Dedup.
$dup = saveSnippet('json', $left);
eq('duplicate returns same hash', $hash, $dup['hash'] ?? '');

// Title.
$titled = saveSnippet('json', 'titled-body-' . uniqid(), null, 'My Title');
eq('title stored', 'My Title', $titled['title'] ?? '');

// Title >255 chars is truncated.
$longTitle = str_repeat('x', 500);
$lt = saveSnippet('json', 'long-title-' . uniqid(), null, $longTitle);
check('title truncated to 255', strlen($lt['title'] ?? '') <= 255);

// Unicode title survives.
$uTitle = 'Tîtle 🔥 éè';
$ut = saveSnippet('json', 'unicode-title-' . uniqid(), null, $uTitle);
eq('unicode title round-trip', $uTitle, $ut['title'] ?? '');

// getSnippet with unknown / empty hash.
check('unknown hash null', getSnippet('nope-nope-nope') === null);
check('empty hash null', getSnippet('') === null);

echo "\n== snippets: share.php endpoint ==\n";

// --- happy path -------------------------------------------------------------------------

$r = http_subprocess('share.php', ['left' => '{"ep":true}', 'format' => 'json', 'title' => 'endpoint title']);
eq('share status', 200, $r['status']);
check('share returns hash', !empty($r['json']['hash']));
$epHash = $r['json']['hash'];

// Fetch back.
$fetched = getSnippet($epHash);
check('shared snippet retrievable', is_array($fetched));
eq('shared body round-trip', '{"ep":true}', html_entity_decode(htmlSafeSnippet($fetched['snippetLeft'])));
eq('shared title', 'endpoint title', $fetched['title'] ?? '');

// --- validation ----------------------------------------------------------------------------

$r = http_subprocess('share.php', []);
eq('share empty 400', 400, $r['status']);

$r = http_subprocess('share.php', ['left' => 'body']);
eq('share no format 400', 400, $r['status']);

$r = http_subprocess('share.php', ['left' => 'body', 'format' => 'not-a-format']);
eq('share bad format 400', 400, $r['status']);

// Every whitelisted format is accepted.
foreach (array_keys($formats) as $fmt) {
    $r = http_subprocess('share.php', ['left' => 'x', 'format' => $fmt]);
    check("format $fmt accepted", !empty($r['json']['hash']));
}

// Format value must not leak into JS unescaped (stored XSS regression — audit #5).
$body = '<img src=x onerror=alert(1)>' . uniqid();
$r = http_subprocess('share.php', ['left' => $body, 'format' => 'text']);
$re = getSnippet($r['json']['hash'] ?? '');
check('xss body saved', is_array($re));
$restored = html_entity_decode(htmlSafeSnippet($re['snippetLeft'] ?? ''));
eq('xss body round-trips', $body, $restored);
// Stored form must be entity-encoded (double-escape pattern).
$raw = gzuncompress(base64_decode($re['snippetLeft']));
check('stored body entity-encoded', strpos($raw, '<img') === false);

test_summary('snippets');
exit($GLOBALS['failures'] === 0 ? 0 : 1);
