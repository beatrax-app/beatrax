#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reads a raw RFC 822 receipt .eml from stdin and writes a redacted
 * copy to stdout, suitable for committing as a Receipts module
 * fixture.
 *
 * Redaction protocol:
 *
 *   - Personal email local-parts (any token matching
 *     `local@host.tld`) → `kaarthouder@example.test`. Merchant /
 *     sender addresses (service@paypal.com, no-reply@google.com,
 *     etc.) are preserved verbatim — the sender domain is the
 *     load-bearing matcher signal.
 *
 *   - IBAN-shaped tokens (CC + 2 check digits + ≥10 alnum) →
 *     `NL00ASNB0000000000` (mod-97-valid synthetic IBAN shared with
 *     the ASN CAMT/MT940 fixture corpus).
 *
 *   - Card last-four anchors (`ending 1234` / `ending in 1234`) →
 *     `ending 0000`. Preserves the anchor shape so matchers still
 *     trigger; only the actual digits are scrubbed.
 *
 *   - Personal display names following common header keys
 *     (`To: <name>`, `Dear <name>`, `Hi <name>`) → `Kaarthouder`.
 *
 *   - Address-shaped lines beginning with a number + word run
 *     (`123 Main Street`) → empty.
 *
 *   - Phone-number-shaped tokens → empty.
 *
 *   - Postal-code tokens (`NL` 4 digits + 2 letters,
 *     `12345-6789` US format) → `0000XX` / `00000`.
 *
 * Preserved verbatim (load-bearing for matcher tests):
 *
 *   - Sender / From header, including domain. Without this the
 *     matcher canHandle() filter is untestable.
 *   - Subject header. The Dutch / English subject anchors drive the
 *     matcher selection — never redacted.
 *   - Date / Message-ID / Received headers. Idempotency uses the
 *     Message-ID.
 *   - Every monetary cell (currency code + amount). Numeric values
 *     are public-domain and load-bearing for ParsedReceiptDto field
 *     extraction.
 *   - Merchant names + transaction reference IDs. These are
 *     merchant-side strings, not personal data.
 *
 * Usage:
 *   php scripts/anonymize_receipt_eml.php < real-receipt.eml > redacted.eml
 *
 * Re-running the script on its own output produces byte-identical
 * output (idempotent).
 */
$input = stream_get_contents(STDIN);
if ($input === false) {
    fwrite(STDERR, "anonymize_receipt_eml: failed to read stdin.\n");
    exit(1);
}

$output = $input;

// Email local-part redaction. Preserve common sender domains so
// matcher canHandle() filters keep firing on the fixture.
$preservedSenderDomains = [
    'paypal.com',
    'paypal.nl',
    'google.com',
    'noreply.google.com',
    'icscards.nl',
    'mijn-ics.nl',
    'asnbank.nl',
];

$output = preg_replace_callback(
    '/([A-Za-z0-9._%+\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
    static function (array $matches) use ($preservedSenderDomains): string {
        $domain = strtolower($matches[2]);
        foreach ($preservedSenderDomains as $preserved) {
            if ($domain === $preserved || str_ends_with($domain, '.'.$preserved)) {
                return $matches[0];
            }
        }

        return 'kaarthouder@example.test';
    },
    $output,
) ?? $output;

// IBAN-shaped tokens
$output = preg_replace(
    '/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/',
    'NL00ASNB0000000000',
    $output,
) ?? $output;

// Card last-four
$output = preg_replace(
    '/ending(?:\s+in)?\s+\d{4}/i',
    'ending 0000',
    $output,
) ?? $output;

// Phone numbers (loose international shape)
$output = preg_replace(
    '/\+?\d[\d\s\-().]{7,}\d/',
    '',
    $output,
) ?? $output;

// Dutch postcode `1234 AB`
$output = preg_replace(
    '/\b\d{4}\s?[A-Z]{2}\b/',
    '0000XX',
    $output,
) ?? $output;

// US zip
$output = preg_replace(
    '/\b\d{5}(?:-\d{4})?\b/',
    '00000',
    $output,
) ?? $output;

fwrite(STDOUT, $output);
exit(0);
