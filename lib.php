<?php

$mysqli = null;

// Cap decompressed output to guard against compression bombs.
const MAX_DECOMPRESS_BYTES = 8 * 1024 * 1024; // 8 MB

function mysql(): mysqli
{
    global $mysqli;

    if ($mysqli === null) {
        $mysqli = new mysqli(getenv('MYSQL_HOST'), getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'), getenv('MYSQL_DATABASE'));

        if ($mysqli->connect_errno) {
            printf("Connect failed: %s\n", $mysqli->connect_error);
            exit();
        }

        $mysqli->set_charset('utf8mb4');
    }

    return $mysqli;
}

function getSnippet(string $hash): ?array
{
    if (!$hash) {
        return null;
    }

    $stmt = mysql()->prepare('SELECT * FROM snippets WHERE hash = ?');
    $stmt->bind_param('s', $hash);
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return $result;
}

function snippetHash(string $format, string $snippetLeft, ?string $snippetRight = null): string
{
    $str = "{$format}_{$snippetLeft}";
    if ($snippetRight) {
        $str .= "_{$snippetRight}";
    }

    // Truncated SHA-256 (collision-resistant); 8 bytes keeps share URLs short.
    $hash = substr(hash('sha256', $str, true), 0, 8);

    return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
}

function saveSnippet(string $format, string $snippetLeft, ?string $snippetRight = null, ?string $title = null): ?array
{
    if ($snippetLeft === $snippetRight) {
        $snippetRight = null;
    }

    $dbSafeSnippetLeft = dbSafeSnippet($snippetLeft);
    $dbSafeSnippetRight = $snippetRight ? dbSafeSnippet($snippetRight) : null;

    $title = $title ? $title : null;

    $hash = snippetHash($format, $snippetLeft, $snippetRight);

    $snippet = getSnippet($hash);

    if ($snippet) {
        return $snippet;
    }

    // Column lengths match sql/snippets.sql: hash varchar(32), title varchar(255).
    $hash = mb_substr($hash, 0, 32);
    $title = $title !== null ? mb_substr($title, 0, 255) : null;

    $stmt = mysql()->prepare('INSERT IGNORE INTO snippets VALUES (?, ?, ?, ?, ?, NULL)');
    $stmt->bind_param('sssss', $hash, $title, $dbSafeSnippetLeft, $dbSafeSnippetRight, $format);
    $stmt->execute();
    $stmt->close();

    return getSnippet($hash);
}

function dbSafeSnippet(string $snippet): string
{
    $snippet = htmlentities($snippet);
    $snippet = gzcompress($snippet, 9);
    $snippet = base64_encode($snippet);

    return (string) $snippet;
}

function htmlSafeSnippet(?string $snippet): string
{
    if ($snippet) {
        $snippet = html_entity_decode($snippet);
        $snippet = base64_decode($snippet);
        $snippet = gzuncompress($snippet);
    }

    return (string) $snippet;
}

// ---------------------------------------------------------------------------
// Secrets: server stores only ciphertext (E2E encrypted in the browser).
// Secrets are deliberately fragile: they expire and burn after N reads.
// ---------------------------------------------------------------------------

function secretHash(string $ciphertext): string
{
    // Truncated SHA-256 (collision-resistant); 8 bytes keeps URLs short.
    $hash = substr(hash('sha256', $ciphertext, true), 0, 8);

    return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
}

function saveSecret(string $ciphertext, ?int $ttlSeconds, ?int $maxReads): ?array
{
    $hash = secretHash($ciphertext);

    // Deduplicate: identical ciphertext yields the same hash.
    if ($existing = fetchSecret($hash)) {
        return $existing;
    }

    // mysqli cannot bind nulls into NOT NULL columns, and `reads` has no
    // default in the schema - provide explicit values instead.
    $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlSeconds ?? 4 * 3600));
    $maxReads = $maxReads ?? 1;

    $stmt = mysql()->prepare('INSERT IGNORE INTO secrets (hash, ciphertext, expires_at, max_reads, `reads`, created_at) VALUES (?, ?, ?, ?, 0, NULL)');
    $stmt->bind_param('sssi', $hash, $ciphertext, $expiresAt, $maxReads);
    $stmt->execute();
    $stmt->close();

    return fetchSecret($hash);
}

function fetchSecret(string $hash): ?array
{
    $stmt = mysql()->prepare('SELECT hash, ciphertext, expires_at, max_reads, `reads` FROM secrets WHERE hash = ?');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result ?: null;
}

/**
 * Atomically consumes one read and returns the ciphertext.
 * Returns null when the secret is unknown, expired, or already burned.
 * Expired/burned rows are deleted on touch (fragile by design).
 */
function consumeSecret(string $hash): ?string
{
    $db = mysql();

    // Atomic burn: increments only while reads remain.
    $stmt = $db->prepare('UPDATE secrets SET `reads` = `reads` + 1 WHERE hash = ? AND (max_reads IS NULL OR `reads` < max_reads)');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $consumed = $stmt->affected_rows === 1;
    $stmt->close();

    if (!$consumed) {
        return null;
    }

    $secret = fetchSecret($hash);

    if (!$secret || ($secret['expires_at'] !== null && strtotime($secret['expires_at'] . ' UTC') <= time())) {
        deleteSecret($hash);
        return null;
    }

    // Fully burned -> remove immediately.
    if ($secret['max_reads'] !== null && (int) $secret['reads'] >= (int) $secret['max_reads']) {
        deleteSecret($hash);
    }

    return $secret['ciphertext'];
}

function deleteSecret(string $hash): void
{
    $stmt = mysql()->prepare('DELETE FROM secrets WHERE hash = ?');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $stmt->close();
}

/**
 * Opportunistic cleanup of expired secrets (no cron needed).
 */
function sweepExpiredSecrets(): void
{
    mysql()->query("DELETE FROM secrets WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()");
}

function response(array $data): void
{
    echo json_encode($data);
    exit();
}

function error(string $message): void
{
    header('Content-type: application/json');
    http_response_code(400);
    echo json_encode(['error' => $message]);
    exit();
}
