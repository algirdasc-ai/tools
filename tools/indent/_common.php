<?php

function indent_common(string $snippet, string $format): string
{
    global $base_url;

    $beautifyUrl = rtrim((string) getenv('BEAUTIFY_URL'), '/') ?: "{$base_url}/tools/beautify";

    $context  = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['snippet' => $snippet, 'format' => $format]),
            'timeout' => 5,
        ]
    ]);

    if ($return = @file_get_contents($beautifyUrl, false, $context)) {
        $return = trim($return);
        if ($return === '') {
            $return = null;
        }
    }

    return $return ?? $snippet;
}