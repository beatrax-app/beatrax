<?php

declare(strict_types=1);

use Modules\Community\Internal\Corpus\MerchantContactReader;
use Psr\Log\NullLogger;

function merchantContactReader(): MerchantContactReader
{
    return new MerchantContactReader(new NullLogger);
}

it('returns null when the entry carries no contact keys at all', function (): void {
    expect(merchantContactReader()->read(['pattern' => 'ALPHA', 'name' => 'Alpha'], 'ALPHA'))->toBeNull();
});

it('reads every contact field off a well-formed entry', function (): void {
    $contact = merchantContactReader()->read([
        'website' => 'https://example.com',
        'cancel_url' => 'https://example.com/account/cancel',
        'support_url' => 'https://example.com/help',
        'support_phone' => '0800 0402',
        'support_email' => 'support@example.com',
    ], 'ALPHA');

    expect($contact)->not->toBeNull();
    expect($contact?->website)->toBe('https://example.com');
    expect($contact?->cancelUrl)->toBe('https://example.com/account/cancel');
    expect($contact?->supportUrl)->toBe('https://example.com/help');
    expect($contact?->supportPhone)->toBe('0800 0402');
    expect($contact?->supportEmail)->toBe('support@example.com');
});

it('trims surrounding whitespace off a contact value', function (): void {
    $contact = merchantContactReader()->read(['website' => "  https://example.com  \n"], 'ALPHA');

    expect($contact?->website)->toBe('https://example.com');
});

it('drops a non-https URL', function (string $url): void {
    expect(merchantContactReader()->read(['cancel_url' => $url], 'ALPHA'))->toBeNull();
})->with([
    'plaintext http' => 'http://example.com/cancel',
    'javascript scheme' => 'javascript:alert(1)',
    'data scheme' => 'data:text/html,<script>alert(1)</script>',
    'protocol-relative' => '//example.com/cancel',
    'bare host' => 'example.com/cancel',
    'https with no host' => 'https://',
]);

it('drops a URL longer than the column can hold verbatim', function (): void {
    $tooLong = 'https://example.com/'.str_repeat('a', MerchantContactReader::URL_MAX);

    expect(merchantContactReader()->read(['support_url' => $tooLong], 'ALPHA'))->toBeNull();
});

it('keeps a URL exactly at the length ceiling', function (): void {
    $prefix = 'https://example.com/';
    $atLimit = $prefix.str_repeat('a', MerchantContactReader::URL_MAX - strlen($prefix));

    expect(merchantContactReader()->read(['support_url' => $atLimit], 'ALPHA')?->supportUrl)->toBe($atLimit);
});

it('keeps a national service number in the notation the merchant publishes', function (string $phone): void {
    expect(merchantContactReader()->read(['support_phone' => $phone], 'ALPHA')?->supportPhone)->toBe($phone);
})->with([
    'dutch freephone' => '0800 0402',
    'e164' => '+31 20 123 4567',
    'parenthesised area code' => '(020) 123-4567',
    'dotted' => '088.121.2812',
    // A national short code has no E.164 form to normalise to, and a six-digit
    // floor used to reject the numbers those customers actually dial.
    'icelandic short code' => '1414',
    'norwegian short code' => '02024',
    'pan-european emergency-style triple' => '112',
    // Real values from the Austrian and German corpus files: the slash splits
    // area code from subscriber number, and the SOS-Kinderdorf number is typeset
    // with an en dash rather than a hyphen. All three were being dropped.
    'austrian slash notation' => '0732/3400-4000',
    'german slash with spaces' => '0800 / 3746 095',
    'en dash before the extension' => '089/12 606 – 0',
]);

it('drops a phone value that is not a dialable number', function (string $phone): void {
    expect(merchantContactReader()->read(['support_phone' => $phone], 'ALPHA'))->toBeNull();
})->with([
    'free text' => 'call us on weekdays',
    'tel scheme smuggled in' => 'tel:+31201234567',
    'letters mixed in' => '0800 CANCEL',
    'a lone stray digit' => '7',
    'leading plus only' => '+',
]);

it('drops an email that could forge extra mailto headers', function (string $email): void {
    expect(merchantContactReader()->read(['support_email' => $email], 'ALPHA'))->toBeNull();
})->with([
    'query separator' => 'support?cc=victim@example.com',
    'ampersand' => 'support&bcc=victim@example.com',
    'whitespace' => 'support @example.com',
    'not an address' => 'support.example.com',
]);

it('keeps the valid fields when a sibling field is rejected', function (): void {
    $contact = merchantContactReader()->read([
        'website' => 'https://example.com',
        'cancel_url' => 'http://example.com/cancel',
    ], 'ALPHA');

    expect($contact?->website)->toBe('https://example.com');
    expect($contact?->cancelUrl)->toBeNull();
});

it('treats an empty-string contact value as absent', function (): void {
    expect(merchantContactReader()->read(['website' => '', 'cancel_url' => '   '], 'ALPHA'))->toBeNull();
});

it('ignores a contact key whose YAML value is not a string', function (): void {
    expect(merchantContactReader()->read(['website' => ['https://example.com'], 'support_phone' => 31201234567], 'ALPHA'))
        ->toBeNull();
});
