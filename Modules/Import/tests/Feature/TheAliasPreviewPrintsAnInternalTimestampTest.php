<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Fmt;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

// The right rail lists the rows an alias would rename, and the date beside each
// one is the reader's only way to recognise it. `booked_at` is the internal
// column: every adapter but the card one writes it as the posted day plus a
// synthetic time whose only job is to keep two same-day fingerprints apart, and
// on a card statement it is the issuer's booking day, not the day of the swipe.
// Both are wrong here for the same reason the transactions list, triage, search
// and the palette all print `posted_at`.

beforeEach(function (): void {
    /** @var User $user */
    $user = User::create([
        'username' => 'alias-preview-date',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->user = $user;

    $this->account = Account::create([
        'user_id' => $user->id,
        'name' => 'ICS',
        'slug' => 'ics-alias-preview-date',
        'kind' => 'ics_card',
        'iban' => 'ICS-ALIAS-PREVIEW-DATE',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/alias-preview-date.pdf',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-25',
        'booked_at' => '2026-05-27 12:00:00',
        'value_date' => '2026-05-25',
        'amount_minor' => -4200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4200,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'ALBERT HEIJN 1234',
        'counterparty_normalized' => 'albert heijn 1234',
        'normalization_version' => 1,
        'description' => 'BETAALAUTOMAAT ALBERT HEIJN 1234',
        'source_format' => 'ics-pdf',
        'import_run_id' => $this->importRun->id,
        'source_row_index' => 1,
        'source_ref' => 'alias-preview-date-1',
        'fingerprint' => str_pad('apd-1', 64, 'z'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'payment_type' => 'unknown',
        'created_at' => '2026-05-27 12:00:00',
        'updated_at' => '2026-05-27 12:00:00',
    ]);

    $this->aliasId = (int) DB::table('merchant_aliases')->insertGetId([
        'user_id' => $user->id,
        'pattern' => 'ALBERT HEIJN 1234',
        'generalized_pattern' => 'albert',
        'friendly_name' => 'Albert Heijn',
        'created_at' => '2026-05-25 10:00:00',
        'updated_at' => '2026-05-25 10:00:00',
    ]);
});

function aliasPreviewHtml(int $aliasId): string
{
    return Livewire::test(AliasesSettingsPage::class)
        ->call('startEdit', $aliasId)
        ->set('editingPattern', 'albert')
        ->html();
}

it('does not print the synthetic time of day the dedup fingerprint carries', function (): void {
    $this->actingAs($this->user);

    expect(aliasPreviewHtml($this->aliasId))->not->toContain('12:00:00');
});

it('prints the day the card was used, the same day every other list prints', function (): void {
    $this->actingAs($this->user);

    expect(aliasPreviewHtml($this->aliasId))->toContain(Fmt::shortDate('2026-05-25'));
});

it('prints the date through the locale formatter, not a fixed pattern', function (): void {
    $this->actingAs($this->user);

    app()->setLocale('nl');

    // nl writes 25-05-2026 where en writes 05/25/2026 corrected to 25/05/2026;
    // a raw echo of the column would read "2026-05-25 …" in both.
    expect(aliasPreviewHtml($this->aliasId))->toContain(Fmt::shortDate('2026-05-25'));
});
