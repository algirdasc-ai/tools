<?php

/**
 * Unit tests for lib.php pure functions (no DB needed for most).
 */

require(__DIR__ . '/bootstrap.php');

$_SERVER['SERVER_NAME'] = 'test.local';
require(__DIR__ . '/../config.php');
require(__DIR__ . '/../lib.php');

echo "== lib.php unit tests ==\n";

// --- snippetHash -----------------------------------------------------------------

$h1 = snippetHash('json', '{"a":1}');
$h2 = snippetHash('json', '{"a":1}');
$h3 = snippetHash('json', '{"a":2}');
$h4 = snippetHash('json', '{"a":1}', 'right');

check('snippetHash deterministic', $h1 === $h2);
check('snippetHash differs for different content', $h1 !== $h3);
check('snippetHash differs when right side added', $h1 !== $h4);
check('snippetHash is URL-safe base64', preg_match('/^[A-Za-z0-9_-]+$/', $h1) === 1);
check('snippetHash length <= 11', strlen($h1) <= 11);

// --- dbSafeSnippet / htmlSafeSnippet round-trip -----------------------------------

$samples = [
    'plain ascii',
    '<script>alert("xss")</script>',
    "quotes ' and \" and \\ backslash",
    "multiline\nwith\r\nbreaks",
    'unicode: héllo wörld ✓ 🔥',
    str_repeat('long ' , 1000),
];

// The full storage pipeline is dbSafeSnippet() then htmlSafeSnippet() then
// html_entity_decode() — htmlSafeSnippet alone returns the entity-encoded
// form BY DESIGN (double-encoding: entity encoding is the XSS guard, the
// browser textarea decodes one layer when rendering).
foreach ($samples as $i => $original) {
    $stored = dbSafeSnippet($original);
    $restored = html_entity_decode(htmlSafeSnippet($stored));
    check("snippet full round-trip #$i", $restored === $original);
    check("stored #$i is base64", base64_decode($stored, true) !== false);
}

// htmlSafeSnippet alone must leave entity encoding intact.
$stored = dbSafeSnippet('<b>x</b>');
$decoded = htmlSafeSnippet($stored);
check('htmlSafeSnippet keeps entities', strpos($decoded, '&lt;b&gt;') !== false);
check('htmlSafeSnippet strips raw html', strpos($decoded, '<b>') === false);

// htmlSafeSnippet must tolerate null/empty
eq('htmlSafeSnippet(null) is empty string', '', htmlSafeSnippet(null));
eq('htmlSafeSnippet(empty) is empty string', '', htmlSafeSnippet(''));

// Stored payload contains no raw HTML (defence in depth: entity-encoded first).
$stored = dbSafeSnippet('<b>bold</b>');
$decoded = gzuncompress(base64_decode($stored));
check('stored payload is entity-encoded', strpos($decoded, '<b>') === false);
check('stored payload contains entities', strpos($decoded, '&lt;b&gt;') !== false);

// --- secretHash --------------------------------------------------------------------

$s1 = secretHash('ciphertext-a');
$s2 = secretHash('ciphertext-a');
$s3 = secretHash('ciphertext-b');
check('secretHash deterministic', $s1 === $s2);
check('secretHash differs for different content', $s1 !== $s3);
check('secretHash URL-safe', preg_match('/^[A-Za-z0-9_-]+$/', $s1) === 1);
check('secretHash != snippetHash for same input', secretHash('x') !== snippetHash('f', 'x'));

test_summary('lib unit');
exit($GLOBALS['failures'] === 0 ? 0 : 1);
