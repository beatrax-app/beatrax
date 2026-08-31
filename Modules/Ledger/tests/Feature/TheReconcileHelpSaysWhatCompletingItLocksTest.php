<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SetTransactionNote;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Services\ReconciliationWriter;

uses(RefreshDatabase::class);

// "Complete reconcile" is the one button on this screen that cannot be undone
// from the screen itself, and nothing beside it said what it does. The help now
// says the rows it covers are locked; this executes that word.

/** @return list<string> every locale the app is offered in */
function reconcileHelpLocales(): array
{
    return array_map(static fn (Locale $case): string => $case->value, Locale::cases());
}

function reconcileHelpReader(): User
{
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    return User::create([
        'username' => 'reconcile-help-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

it('names the button it is describing, in the words that button uses', function (): void {
    $silent = [];

    foreach (reconcileHelpLocales() as $locale) {
        $help = require base_path('Modules/Ledger/Resources/lang/'.$locale.'/help.php');
        $labels = require base_path('Modules/Ledger/Resources/lang/'.$locale.'/reconcile.php');

        $rendered = str_replace(':complete', (string) $labels['complete'], (string) $help['reconcile']);

        if (! str_contains($rendered, (string) $labels['complete'])) {
            $silent[] = $locale;
        }
    }

    expect($silent)->toBe([], implode("\n", [
        'These locales describe a button without using the label printed on it:',
        ...$silent,
    ]));
});

it('translates the sentence rather than shipping the English one', function (): void {
    $english = (require base_path('Modules/Ledger/Resources/lang/en/help.php'))['reconcile'];

    $untranslated = [];
    foreach (array_diff(reconcileHelpLocales(), ['en']) as $locale) {
        $file = base_path('Modules/Ledger/Resources/lang/'.$locale.'/help.php');
        if (! is_file($file)) {
            $untranslated[] = $locale.' (no file)';

            continue;
        }
        if (((require $file)['reconcile'] ?? '') === $english) {
            $untranslated[] = $locale;
        }
    }

    expect($untranslated)->toBe([]);
});

it('means what it says by "locked": the row stops accepting edits', function (): void {
    $user = reconcileHelpReader();
    $this->actingAs($user);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN reconcile help',
        'slug' => 'rh-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/reconcile-help.xml',
        'sha256' => hash('sha256', 'rh-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $transaction = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-10',
        'booked_at' => '2026-07-10 12:00:00',
        'value_date' => '2026-07-10',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'status' => ClearedStatus::Cleared->value,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rh-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
    ]);

    /** @var SetTransactionNote $setNote */
    $setNote = $this->app->make(SetTransactionNote::class);

    expect($setNote($transaction->id, 'before the lock', NoteMode::Set->value, $user))->toBe(1);

    /** @var ReconciliationWriter $writer */
    $writer = $this->app->make(ReconciliationWriter::class);
    $locked = $writer->completeReconcile($user, $account->id, CarbonImmutable::parse('2026-07-31'));

    expect($locked)->toBe(1);
    expect($setNote($transaction->id, 'after the lock', NoteMode::Set->value, $user))->toBe(
        0,
        'The help promises a completed reconcile locks the rows it covers. This one still took an edit.',
    );
});

it('draws the tip beside the heading it explains', function (): void {
    $user = reconcileHelpReader();
    $this->actingAs($user);

    $html = Livewire::test(ReconcilePage::class)->html();

    expect($html)->toContain('id="help-tip-reconcile"')
        ->and($html)->toContain('popovertarget="help-tip-reconcile"')
        ->and($html)->toContain(e(Lang::get('ledger::reconcile.complete')));
});
