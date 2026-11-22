<?php

/**
 * Full test suite runner. Executes every *_test.php in a fresh subprocess,
 * prints per-suite results and a final verdict.
 *
 * Usage (inside the app container):
 *   php tests/run.php
 * From the host:
 *   docker compose exec -T tools php tests/run.php
 * or:
 *   ./tests/run.sh
 */

$suites = glob(__DIR__ . '/*_test.php');
sort($suites);

$failures = 0;
$passes = 0;
$failedSuites = [];

foreach ($suites as $suite) {
    $name = basename($suite);
    echo "\n########## $name ##########\n\n";

    $cmd = sprintf('php %s 2>&1', escapeshellarg($suite));
    exec($cmd, $out, $code);

    foreach ($out as $line) {
        echo $line . "\n";
        if (strpos($line, 'FAIL ') === 0) {
            $failures++;
        } elseif (strpos($line, 'PASS ') === 0) {
            $passes++;
        }
    }

    if ($code !== 0) {
        $failedSuites[] = $name;
    }

    $out = [];
}

echo "\n========================================\n";
echo "TOTAL: $passes passed, $failures failed\n";

if ($failedSuites) {
    echo 'Failed suites: ' . implode(', ', $failedSuites) . "\n";
}

echo $failures === 0 && !$failedSuites ? "ALL SUITES PASSED\n" : "FAILURES PRESENT\n";

exit($failures === 0 && !$failedSuites ? 0 : 1);
