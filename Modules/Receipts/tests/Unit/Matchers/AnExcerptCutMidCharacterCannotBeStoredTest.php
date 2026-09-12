<?php

declare(strict_types=1);

use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

// The excerpt is cut to 200 BYTES of an e-mail body written by somebody
// else. Cutting a Dutch or French receipt mid-character leaves a string
// json_encode() refuses, and both write paths pass JSON_THROW_ON_ERROR --
// so the cut decided whether the receipt could be stored at all.

function excerptMatcher(): IcsReceiptMatcher
{
    return new IcsReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

function excerptEml(int $pad): string
{
    $lead = str_repeat('a', $pad);
    $accented = str_repeat('Café Zürich bevestigt uw betaling. ', 12);

    return "From: noreply@ics.nl\r\n"
        ."To: kaarthouder@example.test\r\n"
        ."Subject: Aankoopnotificatie\r\n"
        ."Date: Sun, 12 Apr 2026 10:15:00 +0200\r\n"
        .sprintf("Message-ID: <ics-excerpt-%s@ics.nl>\r\n", $pad)
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/html; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        ."<html><body>\r\n"
        .sprintf("<p>%s%s</p>\r\n", $lead, $accented)
        ."<table>\r\n"
        ."  <tr><td>Verkoper:</td><td>SYNTHETIC ICS TINY</td></tr>\r\n"
        ."  <tr><td>Bedrag:</td><td>&euro; 1,00 EUR Af</td></tr>\r\n"
        ."  <tr><td>Kaart eindigend op 1234</td></tr>\r\n"
        ."  <tr><td>Referentienummer:</td><td>XYZ123</td></tr>\r\n"
        ."</table>\r\n"
        ."</body></html>\r\n";
}

it('never cuts the stored excerpt through the middle of a character', function (): void {
    $longest = 0;

    // Sweeping the alignment puts the cut inside a two-byte character for
    // some pad whatever the body reader strips ahead of it, so the case is
    // reached without pinning this test to a byte offset it cannot see.
    foreach (range(0, 20) as $pad) {
        $outcome = excerptMatcher()->match(excerptEml($pad));

        expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
        $excerpt = $outcome->parsed?->rawPayload['body_excerpt'] ?? null;
        expect($excerpt)->toBeString();

        /** @var string $excerpt */
        expect(mb_check_encoding($excerpt, 'UTF-8'))
            ->toBeTrue(sprintf('pad %s cut the excerpt mid-character', $pad));

        $longest = max($longest, strlen($excerpt));
    }

    // The control: a body short enough never to be cut would pass the loop
    // above without exercising anything.
    expect($longest)->toBeGreaterThan(190);
});

it('leaves an excerpt that json_encode accepts, which is what stores it', function (): void {
    foreach (range(0, 20) as $pad) {
        $excerpt = excerptMatcher()->match(excerptEml($pad))->parsed?->rawPayload['body_excerpt'] ?? null;

        expect(json_encode(['body_excerpt' => $excerpt], JSON_THROW_ON_ERROR))->toBeString();
    }
});
