<?php

/**
 * Shared test harness. Provides:
 *  - check($name, $condition) / eq($name, $expected, $actual) assertions
 *  - http_post($endpoint, $params) — in-process HTTP-ish call: sets $_POST /
 *    $_GET / $_SERVER, captures output + status code, returns decoded JSON
 *
 * All tests run CLI inside the app container with the dev DB up.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$GLOBALS['failures'] = 0;
$GLOBALS['passes'] = 0;

function check(string $name, bool $condition): void
{
    if ($condition) {
        $GLOBALS['passes']++;
        echo "PASS  $name\n";
    } else {
        $GLOBALS['failures']++;
        echo "FAIL  $name\n";
    }
}

function eq(string $name, $expected, $actual): void
{
    check($name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')', $expected === $actual);
}

/**
 * Simulates a POST (or GET) request against one of the app's endpoints,
 * in-process. Returns ['status' => int, 'json' => array|null, 'raw' => string].
 */
/**
 * Runs an endpoint script in a subprocess (they all exit()) and returns
 * ['status' => int|null, 'json' => array|null, 'raw' => string].
 * The payload is passed via a temp file inside the repo (the container only
 * mounts the working tree, so sys_get_temp_dir() of the host is not visible).
 */
function http_subprocess(string $script, array $post = [], array $get = []): array
{
    $payload = json_encode(['post' => $post, 'get' => $get, 'script' => $script]);
    $payloadFile = __DIR__ . '/.payload_' . getmypid() . '_' . uniqid() . '.json';
    file_put_contents($payloadFile, $payload);

    $cmd = sprintf(
        'php %s %s 2>&1',
        escapeshellarg(__DIR__ . '/endpoint_runner.php'),
        escapeshellarg($payloadFile)
    );

    $raw = shell_exec($cmd);
    unlink($payloadFile);

    $raw = (string) $raw;

    // Status line is printed by the runner as the first line: "STATUS <code>"
    $status = null;
    if (preg_match('/^STATUS (\d+)\n/', $raw, $m)) {
        $status = (int) $m[1];
        $raw = substr($raw, strlen($m[0]));
    }

    $json = json_decode($raw, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $raw];
}

function test_summary(string $suite): void
{
    $total = $GLOBALS['passes'] + $GLOBALS['failures'];
    echo "\n[$suite] {$GLOBALS['passes']}/$total passed\n";
}
