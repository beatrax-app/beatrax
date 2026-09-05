<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\NotificationCopy;
use Modules\Notifications\Public\Services\NotificationQuery;

uses(RefreshDatabase::class);

// The chip is aria-hidden, which hides it from a screen reader and not from
// eyes. The expected words are spelled out here rather than read back from the
// lang file the code reads, because a test that asks the translator the same
// question the subject asks it passes on any answer at all.

/** @return array<string, string> trigger value => the chip word a Dutch reader must see */
function chipWordsDutch(): array
{
    return [
        NotificationTrigger::ImportFinished->value => 'Import',
        NotificationTrigger::ReceiptsFound->value => 'Bon',
        NotificationTrigger::ManualEntryRecorded->value => 'Kas',
        NotificationTrigger::MigrationFinished->value => 'Migratie',
        NotificationTrigger::DriftChanged->value => 'Afwijking',
        NotificationTrigger::ForecastShortfall->value => 'Kastekort',
        NotificationTrigger::PaymentReminder->value => 'Herinnering',
        NotificationTrigger::PositionDigest->value => 'Overzicht',
        NotificationTrigger::BudgetNudge->value => 'Budget',
        NotificationTrigger::SavingsPrompt->value => 'Besparing',
        NotificationTrigger::IcsStatementReady->value => 'Afschrift',
    ];
}

// Dutch keeps "Import" and "Budget" as loanwords, so those two chips read the
// same in both languages and prove nothing on their own. Spanish translates
// all eleven, which is what makes the "no chip is still English" rule below
// answerable.
/** @return array<string, string> trigger value => the chip word a Spanish reader must see */
function chipWordsSpanish(): array
{
    return [
        NotificationTrigger::ImportFinished->value => 'Importación',
        NotificationTrigger::ReceiptsFound->value => 'Recibo',
        NotificationTrigger::ManualEntryRecorded->value => 'Caja',
        NotificationTrigger::MigrationFinished->value => 'Migración',
        NotificationTrigger::DriftChanged->value => 'Desviación',
        NotificationTrigger::ForecastShortfall->value => 'Déficit',
        NotificationTrigger::PaymentReminder->value => 'Recordatorio',
        NotificationTrigger::PositionDigest->value => 'Resumen',
        NotificationTrigger::BudgetNudge->value => 'Presupuesto',
        NotificationTrigger::SavingsPrompt->value => 'Ahorro',
        NotificationTrigger::IcsStatementReady->value => 'Extracto',
    ];
}

/** @return array<string, string> trigger value => the word this build shipped in English */
function chipWordsEnglish(): array
{
    return [
        NotificationTrigger::ImportFinished->value => 'Import',
        NotificationTrigger::ReceiptsFound->value => 'Receipt',
        NotificationTrigger::ManualEntryRecorded->value => 'Cash',
        NotificationTrigger::MigrationFinished->value => 'Migration',
        NotificationTrigger::DriftChanged->value => 'Drift',
        NotificationTrigger::ForecastShortfall->value => 'Shortfall',
        NotificationTrigger::PaymentReminder->value => 'Reminder',
        NotificationTrigger::PositionDigest->value => 'Digest',
        NotificationTrigger::BudgetNudge->value => 'Budget',
        NotificationTrigger::SavingsPrompt->value => 'Savings',
        NotificationTrigger::IcsStatementReady->value => 'Statement',
    ];
}

/** @return array<string, string> rendered target kind => the whole sentence a Dutch reader must see */
function chipDeadLinkDutch(): array
{
    return [
        'series' => 'Deze reeks bestaat niet meer.',
        'budget' => 'Dit budget bestaat niet meer.',
        'counterparty' => 'Deze tegenpartij bestaat niet meer.',
        'transaction' => 'Deze transactie bestaat niet meer.',
        'item' => 'Dit item bestaat niet meer.',
    ];
}

/** @return array<string, string> trigger value => the word the chip actually renders now */
function chipWordsIn(string $locale): array
{
    app()->setLocale($locale);

    $words = [];
    foreach (NotificationTrigger::cases() as $trigger) {
        $words[$trigger->value] = NotificationCopy::typeChip($trigger)['word'];
    }

    return $words;
}

function chipReader(string $username, string $locale): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function chipNotification(User $user, string $id, array $overrides = []): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('notifications')->insert(array_merge([
        'id' => $id,
        'user_id' => $user->id,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Nieuwe bonnen gevonden',
        'body' => '3 bonnen gekoppeld uit je e-mail.',
        'params' => json_encode(['target_kind' => 'series', 'target_id' => 999999], JSON_THROW_ON_ERROR),
        'trigger_type' => NotificationTrigger::ReceiptsFound->value,
        'created_at' => '2026-08-15 09:00:00',
        'updated_at' => '2026-08-15 09:00:00',
    ], $overrides));
}

afterEach(function (): void {
    app()->setLocale('en');
});

it('names all eleven triggers in the language the reader is reading', function (string $locale, array $expected): void {
    $actual = chipWordsIn($locale);

    expect($actual)->toHaveCount(11);

    $wrong = [];
    foreach ($expected as $trigger => $word) {
        if (($actual[$trigger] ?? null) !== $word) {
            $wrong[] = $trigger.': expected "'.$word.'", got "'.($actual[$trigger] ?? 'nothing').'"';
        }
    }

    expect($wrong)->toBe([], $locale." chips are not in the reader's language:\n  ".implode("\n  ", $wrong));
})->with([
    'nl' => ['nl', chipWordsDutch()],
    'es' => ['es', chipWordsSpanish()],
]);

it('leaves no chip standing in English beside a Spanish title', function (): void {
    $spanish = chipWordsIn('es');

    $english = [];
    foreach (chipWordsEnglish() as $trigger => $word) {
        if (($spanish[$trigger] ?? null) === $word) {
            $english[] = $trigger.' still reads "'.$word.'"';
        }
    }

    expect($english)->toBe([], "English chips on a Spanish screen:\n  ".implode("\n  ", $english));
});

// A word resolved once and kept would be right for whoever read first and
// wrong for everyone after, which is the shape a per-request cache gives this.
it('answers the reader in front of it rather than the one it answered first', function (): void {
    $dutch = chipWordsIn('nl');
    $german = chipWordsIn('de');
    $backToDutch = chipWordsIn('nl');

    expect($dutch[NotificationTrigger::ReceiptsFound->value])->toBe('Bon')
        ->and($german[NotificationTrigger::ReceiptsFound->value])->toBe('Beleg')
        ->and($backToDutch[NotificationTrigger::ReceiptsFound->value])->toBe('Bon');
});

it('degrades a trigger this build cannot name to the neutral chip, in the readers language', function (): void {
    app()->setLocale('nl');

    $unknown = NotificationCopy::typeChip(NotificationTrigger::tryFrom('a_kind_a_later_release_writes'));
    $sealed = NotificationCopy::typeChip(NotificationTrigger::tryFrom(''));

    expect($unknown)->toBe($sealed)
        ->and($unknown['glyph'])->toBe('◌')
        ->and($unknown['word'])->toBe('Melding')
        ->and($unknown['word'])->not->toContain('::')
        ->and(NotificationTrigger::tryFrom('a_kind_a_later_release_writes'))->toBeNull();
});

it('keeps the envelope chip ending in its presentation selector', function (): void {
    $glyph = NotificationCopy::typeChip(NotificationTrigger::ReceiptsFound)['glyph'];

    expect(bin2hex($glyph))->toBe('e29c89efb88f')
        ->and($glyph)->toBe("\u{2709}\u{FE0F}");
});

it('names every target kind in a whole sentence the reader can read', function (): void {
    app()->setLocale('nl');

    $wrong = [];
    foreach (chipDeadLinkDutch() as $kind => $sentence) {
        $rendered = Lang::get('notifications::row.dead_link.'.$kind);
        if ($rendered !== $sentence) {
            $wrong[] = $kind.': expected "'.$sentence.'", got "'.$rendered.'"';
        }
    }

    expect($wrong)->toBe([], "dead-link sentences are not Dutch:\n  ".implode("\n  ", $wrong));
});

it('puts the Dutch chip and the Dutch dead-link line on the rendered page', function (): void {
    $user = chipReader('chip-nl-reader', 'nl');
    chipNotification($user, str_repeat('b', 64));

    $response = $this->actingAs($user)->get('/notifications?tab=all');

    $response->assertOk();
    $response->assertSee('Bon');
    $response->assertSee('Deze reeks bestaat niet meer.');
    $response->assertDontSee('Receipt');
    $response->assertDontSee('This series no longer exists.');
    $response->assertDontSee('notifications::row');
});

// The kind reaches a translation key, so a payload string this build does not
// know must be folded to the neutral one before it gets there — otherwise a
// newer release's target_kind renders as its own lang key.
it('folds a target kind this build cannot name into the neutral sentence', function (): void {
    $user = chipReader('chip-nl-unknown-kind', 'nl');
    chipNotification($user, str_repeat('c', 64), [
        'params' => json_encode(
            ['target_kind' => 'a_kind_a_later_release_writes', 'target_id' => 999999],
            JSON_THROW_ON_ERROR,
        ),
    ]);

    /** @var NotificationQuery $query */
    $query = app(NotificationQuery::class);

    /** @var DeepLinkResolver $deepLinks */
    $deepLinks = app(DeepLinkResolver::class);

    $rows = $query->allForUser($user)['rows'];
    expect($rows)->toHaveCount(1);

    $row = $deepLinks->resolve($rows[0], $user);

    app()->setLocale('nl');

    expect($row->targetKind)->toBe('item')
        ->and($row->deepLinkDisabled)->toBeTrue()
        ->and(Lang::get('notifications::row.dead_link.'.$row->targetKind))
        ->toBe('Dit item bestaat niet meer.');
});

it('ships a chip and a dead-link sentence in all twenty-six locales', function (): void {
    $locales = array_map(
        static fn (string $dir): string => basename($dir),
        glob(base_path('Modules/Notifications/Resources/lang/*'), GLOB_ONLYDIR) ?: [],
    );

    expect($locales)->toHaveCount(26);

    $chipKeys = [
        'import', 'receipt', 'cash', 'migration', 'drift', 'shortfall',
        'reminder', 'digest', 'budget', 'savings', 'statement', 'unnamed',
    ];

    $missing = [];
    foreach ($locales as $locale) {
        $lines = require base_path('Modules/Notifications/Resources/lang/'.$locale.'/row.php');

        foreach ($chipKeys as $key) {
            $word = $lines['chip'][$key] ?? null;
            if (! is_string($word) || trim($word) === '') {
                $missing[] = $locale.': chip.'.$key;
            }
        }

        foreach (array_keys(chipDeadLinkDutch()) as $kind) {
            $sentence = $lines['dead_link'][$kind] ?? null;
            if (! is_string($sentence) || trim($sentence) === '') {
                $missing[] = $locale.': dead_link.'.$kind;
            }
        }
    }

    expect($missing)->toBe([], "lines missing from a locale:\n  ".implode("\n  ", $missing));
});
