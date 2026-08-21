<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Notifications\Internal\Support\CopyLine;
use Modules\Notifications\Internal\Support\CopyParam;
use Modules\Notifications\Internal\Support\NotificationCopySpec;

function copyLineRoundTrip(NotificationCopySpec $spec): NotificationCopySpec
{
    $json = json_encode($spec->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $rebuilt = NotificationCopySpec::fromArray($decoded);
    expect($rebuilt)->not->toBeNull();

    /** @var NotificationCopySpec $rebuilt */
    return $rebuilt;
}

it('survives a JSON round trip and renders in whichever language is active', function (): void {
    $spec = NotificationCopySpec::of(
        CopyLine::of('notifications::copy.title.import_finished'),
        CopyLine::plural('notifications::copy.body.import_finished', 22),
    );

    $rebuilt = copyLineRoundTrip($spec);

    app()->setLocale('en');
    expect($rebuilt->title())->toBe('Import finished');
    expect($rebuilt->body())->toBe('22 transactions imported.');

    app()->setLocale('nl');
    expect($rebuilt->title())->toBe('Import voltooid');
    expect($rebuilt->body())->toBe('22 transacties geïmporteerd.');
});

it('resolves a weekday and a short date against the reading language', function (): void {
    $due = CarbonImmutable::parse('2026-08-25');
    $spec = NotificationCopySpec::of(
        CopyLine::of('notifications::copy.title.payment_reminder_confident', ['day' => CopyParam::dayName($due)]),
        CopyLine::of('notifications::copy.body.payment_reminder_confident', [
            'name' => 'Netflix',
            'day' => CopyParam::dayName($due),
            'date' => CopyParam::shortDate($due),
            'amount' => '€ 12,99',
        ]),
    );

    $rebuilt = copyLineRoundTrip($spec);

    app()->setLocale('en');
    CarbonImmutable::setLocale('en');
    expect($rebuilt->title())->toBe('Payment due Tuesday');

    app()->setLocale('nl');
    CarbonImmutable::setLocale('nl');
    expect($rebuilt->title())->toBe('Betaling op dinsdag');
    expect($rebuilt->body())->toContain('Netflix');
    expect($rebuilt->body())->toContain('dinsdag');
});

it('resolves a nested translation key rather than freezing its rendering', function (): void {
    $spec = NotificationCopySpec::of(
        CopyLine::of('notifications::copy.title.drift'),
        CopyLine::of('notifications::copy.body.drift', [
            'direction' => CopyParam::line('notifications::copy.drift_direction.up'),
            'delta' => '12,50',
            'currency' => 'EUR',
        ]),
    );

    $rebuilt = copyLineRoundTrip($spec);

    app()->setLocale('en');
    expect($rebuilt->body())->toBe('A recurring charge moved up by 12,50 EUR.');

    app()->setLocale('nl');
    expect($rebuilt->body())->toBe('Een terugkerende afschrijving ging omhoog met 12,50 EUR.');
});

it('joins a multi-line body in order', function (): void {
    $spec = NotificationCopySpec::make(
        CopyLine::of('notifications::copy.title.position_digest_daily'),
        [
            CopyLine::of('notifications::copy.digest.shortfall'),
            CopyLine::plural('notifications::copy.digest.payments_due', 3),
        ],
    );

    app()->setLocale('nl');
    expect(copyLineRoundTrip($spec)->body())
        ->toBe('Er komt een kastekort aan. 3 betalingen deze periode.');
});

it('reads a malformed or absent spec as no spec at all', function (mixed $raw): void {
    expect(NotificationCopySpec::fromArray($raw))->toBeNull();
})->with([
    'null' => [null],
    'scalar' => ['not-a-spec'],
    'target-only params' => [['target_kind' => 'import']],
    'empty body' => [[['key' => 'a', 'replace' => [], 'count' => null], 'body' => []]],
    'body line without a key' => [['title' => ['key' => 'a', 'replace' => [], 'count' => null], 'body' => [['replace' => []]]]],
    'unknown param kind' => [['title' => ['key' => 'a', 'replace' => ['x' => ['kind' => 'sql', 'value' => 'drop']], 'count' => null], 'body' => [['key' => 'b']]]],
]);
