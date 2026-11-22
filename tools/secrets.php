<?php
// View mode is keyed off the hash URL param, not $activeSnippet (which comes
// from the snippets table - secret hashes live in the secrets table).
$secretHash = trim($_GET['hash'] ?? '');
?>
<?php if (!$secretHash) { /* Create mode */ ?>
<div class="row">
    <div class="col-6">
        <h5><i class="bi bi-shield-lock"></i> Share a secret</h5>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            Secrets are encrypted in your browser (AES-256-GCM) — the server never sees the plaintext or the key.
            By default a secret <strong>burns after a single view</strong> and expires after <strong>4 hours</strong>.
            It is <strong>not</strong> stored anywhere else: once burned or expired it is unrecoverable. That's the point.
        </div>
        <textarea id="secret-input" class="form-control mb-2" rows="8" maxlength="4096" placeholder="Type or paste the secret here..."></textarea>
        <div class="row g-2 mb-2">
            <div class="col-6">
                <label class="form-label mb-0" for="secret-ttl">Expires after</label>
                <select id="secret-ttl" class="form-control">
                    <option value="3600">1 hour</option>
                    <option value="14400" selected>4 hours</option>
                    <option value="86400">24 hours</option>
                    <option value="604800">7 days</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label mb-0" for="secret-max-reads">Allowed views</label>
                <select id="secret-max-reads" class="form-control">
                    <option value="1" selected>1 (burn after reading)</option>
                    <option value="3">3</option>
                    <option value="5">5</option>
                    <option value="10">10</option>
                </select>
            </div>
        </div>
        <button type="button" class="btn btn-primary" id="function-create-secret">
            <i class="bi bi-lock"></i> Encrypt &amp; create link
        </button>
    </div>
    <div class="col-6">
        <h5><i class="bi bi-link-45deg"></i> Share link</h5>
        <div class="alert alert-info">
            The key is in the URL after <code>#</code> and is never sent to the server.
            Anyone with the full link can read the secret — share it over a trusted channel.
        </div>
        <div class="input-group">
            <input type="text" id="secret-link" class="form-control" readonly placeholder="The link will appear here" />
            <button class="btn btn-outline-primary" type="button" id="function-copy-secret" disabled><i class="bi bi-clipboard"></i></button>
        </div>
    </div>
</div>
<?php } else { /* View mode: JS auto-fetches & decrypts using the #fragment key */ ?>
<div class="row">
    <div class="col-8 offset-2">
        <h5><i class="bi bi-shield-lock"></i> Shared secret</h5>
        <div class="alert alert-danger" id="secret-burn-notice">
            <i class="bi bi-fire"></i>
            <strong>This secret has now been burned.</strong> It was deleted from the server the moment you opened it
            and cannot be read again. Copy it now if you need it.
        </div>
        <div id="secret-status" class="alert alert-secondary d-none"></div>
        <pre class="border p-3 bg-light d-none" style="white-space: pre-wrap; word-break: break-word;"><code id="secret-output"></code></pre>
        <button type="button" class="btn btn-outline-primary d-none" id="function-copy-revealed"><i class="bi bi-clipboard"></i> Copy secret</button>
    </div>
</div>
<script>
    const activeSecretHash = <?= json_encode($secretHash) ?>;
</script>
<?php } ?>
