<?php

require('config.php');
require('lib.php');
require('tools/indent/json.php');
require('tools/indent/xml.php');

header('Content-Type: application/json');

$base64 = trim($_POST['base64'] ?? '');

if (!$base64) {
    error('Invalid request');
}

$binary = base64_decode($base64, true);

// Hex path: only attempt if input is valid, even-length hex (avoids hex2bin warnings).
$hex = str_replace('0x', '', $base64);
$hexBinary = (ctype_xdigit($hex) && strlen($hex) % 2 === 0) ? hex2bin($hex) : false;

$data = $binary !== false ? @gzuncompress($binary, MAX_DECOMPRESS_BYTES) : false;

if (!$data && $hexBinary !== false) {
    $data = @gzuncompress($hexBinary, MAX_DECOMPRESS_BYTES);
}

if (!$data) {
    error('Failed to uncompress given string');
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