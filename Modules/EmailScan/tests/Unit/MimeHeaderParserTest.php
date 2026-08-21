<?php

declare(strict_types=1);

use Modules\EmailScan\Internal\MimeHeaderParser;

// Only the PayPal fixture carries a Q-encoded subject; ICS and Google Play
// have plain Subject headers that round-trip verbatim.

beforeEach(function (): void {
    $this->parser = new MimeHeaderParser;
    $this->fixtureRoot = __DIR__.'/../fixtures/eml';
    // A date no fixture could produce, so a fallback leaking into an entry
    // stands out in the assertion message.
    $this->fallback = new DateTimeImmutable('2000-01-01 00:00:00+00:00');
});

it('extracts From + Subject + Date from the PayPal Q-encoded fixture', function (): void {
    $raw = (string) file_get_contents($this->fixtureRoot.'/paypal/sample-receipt.eml');
    $headers = $this->parser->parseHeadersWithFallbackDate($raw, $this->fallback);

    expect($headers->senderEmail)->toBe('service@paypal.com');
    expect($headers->senderName)->toBe('PayPal');
    expect($headers->subject)->toBe('Bedankt voor je betaling aan Synthetic Merchant BV');
    expect($headers->internalDate->format('Y-m-d H:i:sP'))->toBe('2026-05-11 09:14:21+00:00');
});

it('extracts From + Subject + Date from the ICS plain-subject fixture', function (): void {
    $raw = (string) file_get_contents($this->fixtureRoot.'/ics/sample-statement-notice.eml');
    $headers = $this->parser->parseHeadersWithFallbackDate($raw, $this->fallback);

    expect($headers->senderEmail)->toBe('noreply@ics.nl');
    expect($headers->senderName)->toBe('ICS Cards');
    expect($headers->subject)->toBe('Je nieuwe maandafschrift staat klaar');
    expect($headers->internalDate->format('Y-m-d H:i:sP'))->toBe('2026-05-12 06:00:13+00:00');
});

it('extracts From + Subject + Date from the Google Play fixture', function (): void {
    $raw = (string) file_get_contents($this->fixtureRoot.'/googleplay/sample-purchase.eml');
    $headers = $this->parser->parseHeadersWithFallbackDate($raw, $this->fallback);

    expect($headers->senderEmail)->toBe('googleplay-noreply@google.com');
    expect($headers->senderName)->toBe('Google Play');
    expect($headers->subject)->toBe('Your Google Play Order Receipt');
    expect($headers->internalDate->format('Y-m-d H:i:sP'))->toBe('2026-05-13 17:45:49+00:00');
});

it('lowercases an upper-case sender address at parse time (normalisation rule)', function (): void {
    $raw = "From: \"Mixed Case\" <Service@PAYPAL.com>\r\n"
        ."To: <local-user@example.test>\r\n"
        ."Subject: shouty subject\r\n"
        ."Date: Mon, 11 May 2026 09:14:21 +0000\r\n"
        ."\r\n"
        .'body';

    $headers = $this->parser->parseHeadersWithFallbackDate($raw, $this->fallback);

    expect($headers->senderEmail)->toBe('service@paypal.com');
});

it('falls back to the supplied date when the Date header is missing', function (): void {
    $raw = "From: \"PayPal\" <service@paypal.com>\r\n"
        ."To: <local-user@example.test>\r\n"
        ."Subject: missing-date\r\n"
        ."\r\n"
        .'body';

    $fallback = new DateTimeImmutable('2024-01-15 12:34:56+00:00');
    $headers = $this->parser->parseHeadersWithFallbackDate($raw, $fallback);

    expect($headers->internalDate->format('Y-m-d H:i:sP'))->toBe('2024-01-15 12:34:56+00:00');
});

it('returns null senderName when the From header has no display name', function (): void {
    $raw = "From: <service@paypal.com>\r\n"
        ."To: <local-user@example.test>\r\n"
        ."Subject: anonymous\r\n"
        ."Date: Mon, 11 May 2026 09:14:21 +0000\r\n"
        ."\r\n"
        .'body';

    $headers = $this->parser->parseHeadersWithFallbackDate($raw, $this->fallback);

    expect($headers->senderName)->toBeNull();
});

it('returns null subject when the Subject header is empty', function (): void {
    $raw = "From: \"PayPal\" <service@paypal.com>\r\n"
        ."To: <local-user@example.test>\r\n"
        ."Date: Mon, 11 May 2026 09:14:21 +0000\r\n"
        ."\r\n"
        .'body';

    $headers = $this->parser->parseHeadersWithFallbackDate($raw, $this->fallback);

    expect($headers->subject)->toBeNull();
});
