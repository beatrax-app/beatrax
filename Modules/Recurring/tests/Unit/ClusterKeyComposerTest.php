<?php

declare(strict_types=1);

use Modules\Recurring\Internal\Detection\ClusterKeyComposer;

// The composed key is the payload of the UNIQUE(user_id, direction, cluster_key,
// latest_currency) constraint on recurring_series, so its shape has to be stable.
it('composes a canonical lowercase double-colon-separated key', function (): void {
    $composer = new ClusterKeyComposer;

    expect($composer->compose('expense', 'spotify bv', 'EUR', 'monthly'))
        ->toBe('expense::spotify-bv::eur::monthly');
});

it('collapses punctuation in the counterparty into single hyphens', function (): void {
    $composer = new ClusterKeyComposer;

    expect($composer->compose('expense', 'Acme,  Inc. / Cloud!', 'USD', 'quarterly'))
        ->toBe('expense::acme-inc-cloud::usd::quarterly');
});

it('produces identical output for the same input twice (idempotent)', function (): void {
    $composer = new ClusterKeyComposer;

    $a = $composer->compose('income', 'Employer BV', 'EUR', 'monthly');
    $b = $composer->compose('income', 'Employer BV', 'EUR', 'monthly');

    expect($a)->toBe($b);
});

it('preserves direction + currency + cadence positions when counterparty repeats', function (): void {
    $composer = new ClusterKeyComposer;

    expect($composer->compose('expense', 'netflix', 'USD', 'monthly'))
        ->toBe('expense::netflix::usd::monthly');
    expect($composer->compose('expense', 'netflix', 'EUR', 'monthly'))
        ->toBe('expense::netflix::eur::monthly');
});

it('strips leading/trailing hyphens that punctuation normalisation would otherwise leave', function (): void {
    $composer = new ClusterKeyComposer;

    expect($composer->compose('expense', '!!Spotify!!', 'EUR', 'monthly'))
        ->toBe('expense::spotify::eur::monthly');
});

it('limits each part to 60 characters to fit the cluster_key column', function (): void {
    $composer = new ClusterKeyComposer;

    $long = str_repeat('long-name-', 10);
    $key = $composer->compose('expense', $long, 'EUR', 'monthly');
    $parts = explode('::', $key);

    foreach ($parts as $part) {
        expect(strlen($part))->toBeLessThanOrEqual(60);
    }
});

it('keeps two Greek merchants apart instead of collapsing both to an empty token', function (): void {
    $composer = new ClusterKeyComposer;

    $alpha = $composer->compose('expense', 'Αλφα Καφε', 'EUR', 'monthly');
    $beta = $composer->compose('expense', 'Βητα Ταξι', 'EUR', 'monthly');

    expect($alpha)->not->toBe($beta);
    expect($alpha)->not->toBe('expense::::eur::monthly');
});

it('keeps a Cyrillic merchant name in the key rather than stripping it away', function (): void {
    $composer = new ClusterKeyComposer;

    expect($composer->compose('expense', 'Мосэнерго', 'EUR', 'monthly'))
        ->toBe('expense::мосэнерго::eur::monthly');
});

it('does not merge an ampersand merchant with the space-separated merchant beside it', function (): void {
    $composer = new ClusterKeyComposer;

    expect($composer->compose('expense', 'a&b', 'EUR', 'monthly'))
        ->not->toBe($composer->compose('expense', 'a b', 'EUR', 'monthly'));
});

it('caps a multibyte part on characters so it never cuts a codepoint in half', function (): void {
    $composer = new ClusterKeyComposer;

    $parts = explode('::', $composer->compose('expense', str_repeat('ж', 80), 'EUR', 'monthly'));

    expect(mb_strlen($parts[1], 'UTF-8'))->toBe(60);
    expect(mb_check_encoding($parts[1], 'UTF-8'))->toBeTrue();
});
