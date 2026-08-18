/**
 * Carry a file upload across a runtime that cannot carry multipart.
 *
 * The iOS shell serves the app from a custom `php://` scheme, and WebKit hands
 * a custom scheme handler only string request bodies: a FormData or Blob body
 * arrives as neither `httpBody` nor `httpBodyStream`, so PHP saw a
 * `multipart/form-data` Content-Type with a zero-byte `php://input`. Measured
 * on the device, a urlencoded or JSON string body arrived whole while FormData
 * and Blob arrived as nothing at all. No native patch can repair that; the file
 * has to cross as a string.
 *
 * So it crosses base64-encoded inside a JSON body, and the server rebuilds the
 * bytes and hands Livewire the same temporary file a multipart POST would have.
 * The digest travels with it because "the same bytes" is the whole claim: a
 * transport that quietly truncated a statement would corrupt an import rather
 * than fail it.
 *
 * Only where multipart genuinely cannot cross, and the SERVER says where that
 * is. This used to test location.protocol and treat http as proof that
 * multipart worked — which read Android's real http://127.0.0.1 as safe. It is
 * not: measured on a device, Livewire's multipart POST returns 200 with
 * `{"paths":[]}`, PHP having parsed no file at all, and the component then dies
 * on `Undefined array key 0` in WithFileUploads. Two different runtimes, one
 * fault, and the scheme did not distinguish them.
 *
 * The flag is shared by the same provider that registers the decoding
 * middleware, so the encoder and the decoder cannot disagree.
 */

// Livewire's endpoint carries a per-install prefix (`/livewire-46b0b3d4/…`),
// so the stable part of the path is what identifies it.
const UPLOAD_PATH = '/upload-file';

const TRANSPORT_FIELD = '_beatrax_transport';
const TRANSPORT_MARKER = 'base64';

/** Whether this runtime can carry a multipart body at all. */
function carriesMultipart() {
    return document.querySelector('meta[name="beatrax-upload-transport"][content="base64"]') === null;
}

/*
 * Chunked because `btoa(String.fromCharCode(...bytes))` spreads every byte as
 * an argument, and a statement of any size overflows the argument limit.
 */
function toBase64(bytes) {
    const chunkSize = 0x8000;
    let binary = '';

    for (let offset = 0; offset < bytes.length; offset += chunkSize) {
        binary += String.fromCharCode.apply(null, bytes.subarray(offset, offset + chunkSize));
    }

    return window.btoa(binary);
}

async function sha256Hex(buffer) {
    const digest = await window.crypto.subtle.digest('SHA-256', buffer);

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

async function encodeFile(key, file) {
    const buffer = await file.arrayBuffer();

    return {
        key,
        name: file.name || 'upload',
        type: file.type || '',
        size: buffer.byteLength,
        sha256: await sha256Hex(buffer),
        content: toBase64(new Uint8Array(buffer)),
    };
}

/** Splits a FormData into the files to encode and the plain fields to carry. */
function partition(formData) {
    const files = [];
    const fields = {};

    formData.forEach((value, key) => {
        if (value instanceof File || value instanceof Blob) {
            files.push({ key, value });

            return;
        }

        fields[key] = value;
    });

    return { files, fields };
}

/*
 * Re-asserted rather than installed once, because we have to be the OUTERMOST
 * wrapper and we are not the only one wrapping.
 *
 * The Android shell injects its own XHR shim AFTER the page's modules run:
 *
 *     XMLHttpRequest.prototype.send = function (data) {
 *         if (["post","patch","put"].includes(this._method.toLowerCase()) && data) {
 *             var reqId = 'nphp_' + (++_nphpReqId) + ...
 *
 * It replaces the prototype outright, so a hook installed at module load is
 * simply gone by the time an upload happens — which is exactly what the device
 * showed: app.js had demonstrably run (window.ApexCharts was set, the scheme
 * cookie written) and yet prototype.send was the shell's, not ours.
 *
 * That shim is also WHY multipart never arrives. It routes POST bodies through
 * the native bridge, and the bridge cannot carry a FormData — the same reason
 * iOS could not. So this has to sit outside it and hand it a string.
 *
 * install() is idempotent and marks its own work, so re-running it is free.
 */
function install() {
    if (XMLHttpRequest.prototype.send.beatraxTransport === true) {
        return;
    }

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    // The URL is only available at open() time, and send() is where the body
    // shows up, so the one has to be remembered for the other.
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this._beatraxUrl = typeof url === 'string' ? url : String(url);

        return originalOpen.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function (body) {
        const isUpload = typeof this._beatraxUrl === 'string'
            && this._beatraxUrl.includes(UPLOAD_PATH)
            && body instanceof FormData
            && ! carriesMultipart();

        if (! isUpload) {
            return originalSend.call(this, body);
        }

        const { files, fields } = partition(body);

        if (files.length === 0) {
            return originalSend.call(this, body);
        }

        // send() cannot wait for the encode, but nothing is waiting on send()
        // either — the caller listens for load. Sending from the continuation
        // is therefore ordinary, and open() has already run, so a header set
        // here is still in time.
        Promise.all(files.map(({ key, value }) => encodeFile(key, value)))
            .then((encoded) => {
                this.setRequestHeader('Content-Type', 'application/json');

                originalSend.call(this, JSON.stringify({
                    [TRANSPORT_FIELD]: TRANSPORT_MARKER,
                    fields,
                    files: encoded,
                }));
            })
            .catch(() => {
                // Falling back to the multipart body means the upload fails the
                // way it would have without this file, which is the honest
                // outcome: better a failed import than a silent partial one.
                originalSend.call(this, body);
            });

        return undefined;
    };

    XMLHttpRequest.prototype.send.beatraxTransport = true;
}

install();

/*
 * The shell's shim lands at some point after the modules run, and exactly when
 * is its business, not ours. Rather than guess, re-assert at each point the
 * document is known to have moved on. install() returns immediately when we
 * are already outermost, so the repetition costs a property read.
 */
document.addEventListener('DOMContentLoaded', install);
window.addEventListener('load', install);
document.addEventListener('livewire:init', install);
document.addEventListener('livewire:navigated', install);

// A short tail for the shell itself, which injects on its own schedule and
// answers to no document event. Five seconds of one property read, then it
// stops; an upload cannot happen before the user has seen the screen anyway.
let attempts = 0;
const settle = setInterval(() => {
    install();

    if (++attempts >= 10) {
        clearInterval(settle);
    }
}, 500);
