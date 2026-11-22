// E2E secret sharing: AES-256-GCM in the browser. The server stores only
// ciphertext; the key travels exclusively in the URL fragment (#key), which
// browsers never send to the server.

function b64urlEncode(buffer) {
    return btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64urlDecode(str) {
    str = str.replace(/-/g, '+').replace(/_/g, '/');
    while (str.length % 4) {
        str += '=';
    }
    return Uint8Array.from(atob(str), c => c.charCodeAt(0));
}

function secretStatus(message, cssClass) {
    const el = $('#secret-status');
    el.removeClass('d-none alert-secondary alert-danger').addClass(cssClass).text(message);
}

// ---------------------------------------------------------------------------
// Create mode
// ---------------------------------------------------------------------------

$('#function-create-secret').on('click', async function () {
    const btn = $(this);
    const plaintext = $('#secret-input').val();

    if (!plaintext) {
        showToast('Secret is empty', 'bg-danger');
        return;
    }

    setButtonBusy(btn);

    try {
        const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt']);
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const encoded = new TextEncoder().encode(plaintext);
        const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, encoded);

        // Payload: iv || ciphertext||tag, base64-encoded for transport.
        const payload = new Uint8Array(iv.length + encrypted.byteLength);
        payload.set(iv, 0);
        payload.set(new Uint8Array(encrypted), iv.length);

        const rawKey = await crypto.subtle.exportKey('raw', key);

        ajax('/secret_save.php',
            {
                ciphertext: btoa(String.fromCharCode(...payload)),
                ttl: $('#secret-ttl').val(),
                max_reads: $('#secret-max-reads').val(),
            },
            function (response) {
                const link = window.location.origin + '/s/' + response.hash + '#' + b64urlEncode(rawKey);
                $('#secret-link').val(link);
                $('#function-copy-secret').removeAttr('disabled');
                showToast('<i class="bi bi-shield-lock"></i> Secret encrypted. Share the link — it burns after reading.', 'bg-success');
                setButtonReady(btn);
            },
            handleAjaxError,
            function () {
                setButtonReady(btn);
            }
        );
    } catch (e) {
        showToast('Encryption failed: ' + e.message, 'bg-danger');
        setButtonReady(btn);
    }
});

$('#function-copy-secret').on('click', function () {
    const link = $('#secret-link').val();
    if (link) {
        navigator.clipboard.writeText(link);
        showToast('<i class="bi bi-hand-thumbs-up-fill"></i> URL copied to clipboard', 'bg-success');
    }
});

// ---------------------------------------------------------------------------
// View mode: fetch ciphertext (burns it), decrypt with the #fragment key
// ---------------------------------------------------------------------------

if (typeof activeSecretHash === 'string' && activeSecretHash) {
    (async function () {
        const fragment = window.location.hash.substring(1);

        if (!fragment) {
            $('#secret-burn-notice').addClass('d-none');
            secretStatus('No decryption key in this link (missing #fragment). The secret cannot be recovered without it.', 'alert-danger');
            return;
        }

        let key;
        try {
            key = await crypto.subtle.importKey('raw', b64urlDecode(fragment), { name: 'AES-GCM' }, false, ['decrypt']);
        } catch (e) {
            $('#secret-burn-notice').addClass('d-none');
            secretStatus('Invalid decryption key in this link.', 'alert-danger');
            return;
        }

        ajax('/secret_read.php',
            { hash: activeSecretHash },
            async function (response) {
                try {
                    const payload = b64urlDecode(response.ciphertext);
                    const iv = payload.slice(0, 12);
                    const data = payload.slice(12);
                    const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv }, key, data);

                    $('#secret-output').text(new TextDecoder().decode(decrypted));
                    $('#secret-output').parent().removeClass('d-none');
                    $('#function-copy-revealed').removeClass('d-none');
                } catch (e) {
                    // Wrong key / tampered ciphertext. Note: the read already
                    // burned the secret server-side - fragile by design.
                    $('#secret-burn-notice').addClass('d-none');
                    secretStatus('Decryption failed — the key does not match this secret. The read already counted, so the secret may now be gone.', 'alert-danger');
                }
            },
            function (xhr) {
                $('#secret-burn-notice').addClass('d-none');
                let message = 'Failed to load the secret.';
                try {
                    message = JSON.parse(xhr.responseText).error || message;
                } catch (e) {}
                secretStatus(message, 'alert-danger');
            }
        );
    })();
}

$('#function-copy-revealed').on('click', function () {
    navigator.clipboard.writeText($('#secret-output').text());
    showToast('<i class="bi bi-hand-thumbs-up-fill"></i> Secret copied to clipboard', 'bg-success');
});
