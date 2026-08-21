<?php

declare(strict_types=1);

use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;

// Byte-identical input has to give byte-identical output on two independent
// devices: that identity is the whole convergence mechanism.

it('produces an identical 64-char hex digest from two independently constructed instances given the same tuple', function (): void {
    $first = new DeterministicKeyDeriver;
    $second = new DeterministicKeyDeriver;

    $a = $first->derive(42, 'import_finished', 'import-run-7', '2026-07-17');
    $b = $second->derive(42, 'import_finished', 'import-run-7', '2026-07-17');

    expect($a)->toBe($b);
    expect($a)->toMatch('/^[a-f0-9]{64}$/');
});

it('changes the digest when user_id changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, 'import_finished', 'import-run-7', '2026-07-17');
    $b = $deriver->derive(2, 'import_finished', 'import-run-7', '2026-07-17');

    expect($a)->not->toBe($b);
});

it('changes the digest when trigger_type changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED, 'subj', 'occ');
    $b = $deriver->derive(1, DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND, 'subj', 'occ');

    expect($a)->not->toBe($b);
});

it('changes the digest when subject_key changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, 'drift_changed', 'series-1', 'occ');
    $b = $deriver->derive(1, 'drift_changed', 'series-2', 'occ');

    expect($a)->not->toBe($b);
});

it('changes the digest when occurrence changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, 'forecast_shortfall', 'subj', '2026-07');
    $b = $deriver->derive(1, 'forecast_shortfall', 'subj', '2026-08');

    expect($a)->not->toBe($b);
});

it('produces a byte-identical digest for unicode subject_key across independent runs', function (): void {
    $first = new DeterministicKeyDeriver;
    $second = new DeterministicKeyDeriver;

    $a = $first->derive(7, 'payment_reminder', 'Müller Café — İstanbul', 'occ-1');
    $b = $second->derive(7, 'payment_reminder', 'Müller Café — İstanbul', 'occ-1');

    expect($a)->toBe($b);
});

it('exposes a public const for each of the 8 trigger-type slugs', function (): void {
    expect(DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED)->toBe('import_finished');
    expect(DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND)->toBe('receipts_found');
    expect(DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED)->toBe('drift_changed');
    expect(DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL)->toBe('forecast_shortfall');
    expect(DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER)->toBe('payment_reminder');
    expect(DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST)->toBe('position_digest');
    expect(DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE)->toBe('budget_nudge');
    expect(DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT)->toBe('savings_prompt');
});
