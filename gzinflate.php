<?php

require('constants.php');
require('tools/indent/json.php');
require('tools/indent/xml.php');

header('Content-Type: application/json');

// Cap inflated output to guard against decompression bombs.
const MAX_INFLATE_BYTES = 8 * 1024 * 1024; // 8 MB

$base64 = trim($_POST['base64'] ?? '');

if (!$base64) {
    error('Invalid request');
}

// Hex path: only attempt if input is valid, even-length hex (avoids hex2bin warnings).
$hex = str_replace('0x', '', $base64);
$binary = (ctype_xdigit($hex) && strlen($hex) % 2 === 0) ? hex2bin($hex) : false;

$data = $binary !== false ? @gzinflate($binary, MAX_INFLATE_BYTES) : false;

if (!$data) {
    $data = @gzinflate(base64_decode($base64), MAX_INFLATE_BYTES);
}

if (!$data) {
    error('Failed to inflate given string');
}

$format = 'text';
$function = null;
if (strpos($data, '{') === 0) {
    $format = 'JSON';
    $function = 'indent_json';
} elseif (strpos($data, '<') === 0) {
	$format = 'XML';
	$function = 'indent_xml';	
}

if ($function !== null) {
	$data = $function($data, $format);
}
$snippet = saveSnippet(strtolower($format), $data, null);

response([
    'redirect' => "{$base_url}/f/{$snippet['hash']}",
    'data' => $data,
]);
