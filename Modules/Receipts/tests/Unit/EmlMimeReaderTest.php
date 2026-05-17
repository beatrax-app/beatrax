<?php

declare(strict_types=1);

use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ParsedMimeMessage;

it('prefers the text/plain part when both text/plain and text/html are present', function (): void {
    $boundary = 'BOUNDARY-MULTI-001';
    $eml = "From: service@paypal.com\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Synthetic multipart receipt\r\n"
        ."Date: Sat, 17 May 2026 10:00:00 +0000\r\n"
        ."Message-ID: <synth-mp-001@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
        ."\r\n"
        ."--{$boundary}\r\n"
        ."Content-Type: text/plain; charset=utf-8\r\n"
        ."Content-Transfer-Encoding: 7bit\r\n"
        ."\r\n"
        ."Plain text body wins.\r\n"
        ."Amount: EUR 12,99\r\n"
        ."\r\n"
        ."--{$boundary}\r\n"
        ."Content-Type: text/html; charset=utf-8\r\n"
        ."Content-Transfer-Encoding: 7bit\r\n"
        ."\r\n"
        ."<html><body><p>HTML body should NOT win.</p></body></html>\r\n"
        ."\r\n"
        ."--{$boundary}--\r\n";

    $reader = new EmlMimeReader;
    $result = $reader->read($eml);

    expect($result)->toBeInstanceOf(ParsedMimeMessage::class);
    expect($result->textBody)->not->toBeNull();
    expect($result->textBody)->toContain('Plain text body wins.');
    expect($result->textBody)->toContain('EUR 12,99');
    expect($result->htmlBody)->toContain('HTML body should NOT win.');
    expect($result->headers['from'] ?? null)->toBe('service@paypal.com');
    expect($result->headers['subject'] ?? null)->toBe('Synthetic multipart receipt');
});

it('falls back to the html body when text/plain is absent', function (): void {
    $eml = "From: noreply@google.com\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Synthetic html-only receipt\r\n"
        ."Date: Sat, 17 May 2026 10:00:00 +0000\r\n"
        ."Message-ID: <synth-html-001@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/html; charset=utf-8\r\n"
        ."Content-Transfer-Encoding: 7bit\r\n"
        ."\r\n"
        ."<html><body><p>Order GPA.1234-5678 confirmed. Total: USD 9.99.</p></body></html>\r\n";

    $reader = new EmlMimeReader;
    $result = $reader->read($eml);

    expect($result->textBody)->toBeNull();
    expect($result->htmlBody)->not->toBeNull();
    expect($result->htmlBody)->toContain('Order GPA.1234-5678 confirmed');
    expect($result->htmlBody)->toContain('USD 9.99');

    // Matchers can perform their own strip-tags fallback when
    // textBody is null. The reader does not pre-strip.
    $stripped = html_entity_decode(strip_tags($result->htmlBody));
    expect($stripped)->toContain('Order GPA.1234-5678 confirmed. Total: USD 9.99.');
});
