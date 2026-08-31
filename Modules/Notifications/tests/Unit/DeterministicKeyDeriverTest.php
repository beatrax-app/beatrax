<?php

declare(strict_types=1);

use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Enums\NotificationTrigger;

// Byte-identical input has to give byte-identical output on two independent
// devices: that identity is the whole convergence mechanism.

it('produces an identical 64-char hex digest from two independently constructed instances given the same tuple', function (): void {
    $first = new DeterministicKeyDeriver;
    $second = new DeterministicKeyDeriver;

    $a = $first->derive(42, NotificationTrigger::from('import_finished'), 'import-run-7', '2026-07-17');
    $b = $second->derive(42, NotificationTrigger::from('import_finished'), 'import-run-7', '2026-07-17');

    expect($a)->toBe($b);
    expect($a)->toMatch('/^[a-f0-9]{64}$/');
});

it('changes the digest when user_id changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, NotificationTrigger::from('import_finished'), 'import-run-7', '2026-07-17');
    $b = $deriver->derive(2, NotificationTrigger::from('import_finished'), 'import-run-7', '2026-07-17');

    expect($a)->not->toBe($b);
});

it('changes the digest when trigger_type changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, NotificationTrigger::ImportFinished, 'subj', 'occ');
    $b = $deriver->derive(1, NotificationTrigger::ReceiptsFound, 'subj', 'occ');

    expect($a)->not->toBe($b);
});

it('changes the digest when subject_key changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, NotificationTrigger::from('drift_changed'), 'series-1', 'occ');
    $b = $deriver->derive(1, NotificationTrigger::from('drift_changed'), 'series-2', 'occ');

    expect($a)->not->toBe($b);
});

it('changes the digest when occurrence changes', function (): void {
    $deriver = new DeterministicKeyDeriver;

    $a = $deriver->derive(1, NotificationTrigger::from('forecast_shortfall'), 'subj', '2026-07');
    $b = $deriver->derive(1, NotificationTrigger::from('forecast_shortfall'), 'subj', '2026-08');

    expect($a)->not->toBe($b);
});

it('produces a byte-identical digest for unicode subject_key across independent runs', function (): void {
    $first = new DeterministicKeyDeriver;
    $second = new DeterministicKeyDeriver;

    $a = $first->derive(7, NotificationTrigger::from('payment_reminder'), 'Müller Café — İstanbul', 'occ-1');
    $b = $second->derive(7, NotificationTrigger::from('payment_reminder'), 'Müller Café — İstanbul', 'occ-1');

    expect($a)->toBe($b);
});

// The strings are what the encrypted trigger_type column already holds on
// every installed device, so they are frozen: renaming one orphans the rows
// written before it.
it('keeps the stored slug of every trigger type', function (): void {
    expect(array_column(NotificationTrigger::cases(), 'value'))->toBe([
        'import_finished',
        'receipts_found',
        'manual_entry_recorded',
        'drift_changed',
        'forecast_shortfall',
        'payment_reminder',
        'position_digest',
        'budget_nudge',
        'savings_prompt',
        'ics_statement_ready',
        'migration_finished',
    ]);
});
