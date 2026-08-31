<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Models\ForecastRun;

uses(RefreshDatabase::class);

// The forecast chart hides its legend and enables a shared tooltip, so the
// only place a series name reaches a reader is that tooltip — and both names
// were English literals in an options array no translated-line arch test
// looks inside.

const SERIES_LOCALE_POINT_MINOR = 250_000;

const SERIES_LOCALE_STEM = 4;

function seriesLocaleUser(): User
{
    return User::query()->create([
        'username' => 'series-locale-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function seriesLocaleAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Betaalrekening',
        'slug' => 'series-locale-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00SER'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'opening_balance_minor' => SERIES_LOCALE_POINT_MINOR,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

function seriesLocaleRun(DatabaseManager $db, int $userId, int $accountId): void
{
    $points = [];
    for ($d = 0; $d <= 30; $d++) {
        $points[] = [
            'date' => (new CarbonImmutable('2026-05-19'))->addDays($d)->toDateString(),
            'low_minor' => SERIES_LOCALE_POINT_MINOR - 1_000,
            'point_minor' => SERIES_LOCALE_POINT_MINOR,
            'high_minor' => SERIES_LOCALE_POINT_MINOR + 1_000,
            'currency' => 'EUR',
        ];
    }

    $run = new ForecastRun;
    $run->user_id = $userId;
    $run->scenario_id = null;
    $run->horizon_days = 30;
    $run->status = 'complete';
    $run->save();

    $db->connection()->table('forecast_runs')->where('id', $run->id)->update([
        'result_json' => json_encode([
            'as_of' => '2026-05-19',
            'horizon_days' => 30,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'Betaalrekening',
                    'default_currency' => 'EUR',
                    'today_balance_minor' => SERIES_LOCALE_POINT_MINOR,
                    'anchor_source' => 'sum_of_transactions',
                    'points' => $points,
                ],
            ],
        ]),
    ]);
}

/**
 * @return list<string>
 */
function seriesLocaleNames(ForecastChartView $view, User $user, int $accountId): array
{
    $options = $view->selectedAccount($accountId, 30, null, $user, 'EUR')['apexOptions'];

    return [$options['series'][0]['name'], $options['series'][1]['name']];
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->user = seriesLocaleUser();
    $this->accountId = seriesLocaleAccount($db, $this->user->id);
    seriesLocaleRun($db, $this->user->id, $this->accountId);
    $this->actingAs($this->user);
});

afterEach(fn () => app()->setLocale(Locale::En->value));

it('names the band and the line in the reader own language', function (): void {
    app()->setLocale(Locale::Nl->value);

    $names = seriesLocaleNames(app(ForecastChartView::class), $this->user, $this->accountId);

    expect($names)->toBe(['Projectiebereik', 'Puntschatting'])
        ->and($names)->not->toContain('Range')
        ->and($names)->not->toContain('Point estimate');
});

it('resolves the names for the reader holding the page, not once for the process', function (): void {
    $view = app(ForecastChartView::class);

    app()->setLocale(Locale::Nl->value);
    $dutch = seriesLocaleNames($view, $this->user, $this->accountId);

    app()->setLocale(Locale::De->value);
    $german = seriesLocaleNames($view, $this->user, $this->accountId);

    expect($dutch)->toBe(['Projectiebereik', 'Puntschatting'])
        ->and($german)->toBe(['Projektionsbereich', 'Punktschätzung']);
});

/**
 * @return list<string>
 */
function seriesLocaleStems(string $line): array
{
    $words = preg_split('/[^\p{L}]+/u', mb_strtolower($line), -1, PREG_SPLIT_NO_EMPTY);

    $stems = [];
    foreach ($words === false ? [] : $words as $word) {
        // Under four letters is a preposition or an article, which inflects
        // away and carries no terminology. Four letters of the rest is enough
        // to tell one lexeme from another and still survive a case ending:
        // Polish writes "estymacja punktowa" as "estymacji punktowej".
        if (mb_strlen($word) >= SERIES_LOCALE_STEM) {
            $stems[] = mb_substr($word, 0, SERIES_LOCALE_STEM);
        }
    }

    return $stems;
}

// The same two ideas already had a rendering in every locale inside
// confidence_chip_aria. Two words for one idea in one language is the defect
// this key pair could reintroduce, so the tooltip is held to the vocabulary
// the aria line already chose.
it('uses the words that locale already chose for the confidence chip', function (): void {
    /** @var Translator $translator */
    $translator = app(Translator::class);

    $strangers = [];
    foreach (Locale::cases() as $locale) {
        $translator->setLocale($locale->value);
        $aria = seriesLocaleStems($translator->get('forecasting::forecast.confidence_chip_aria'));

        foreach (['projection_range', 'point_estimate'] as $key) {
            $line = $translator->get('forecasting::forecast.'.$key);
            expect($line)->toBeString();
            foreach (seriesLocaleStems($line) as $stem) {
                if (! in_array($stem, $aria, true)) {
                    $strangers[] = $locale->value.'.'.$key.': '.$stem;
                }
            }
        }
    }

    expect($strangers)->toBe([]);
});

it('gives every locale its own words for both series', function (): void {
    /** @var Translator $translator */
    $translator = app(Translator::class);

    $english = [];
    $translator->setLocale(Locale::En->value);
    foreach (['projection_range', 'point_estimate'] as $key) {
        $english[$key] = $translator->get('forecasting::forecast.'.$key);
    }

    foreach (Locale::cases() as $locale) {
        $translator->setLocale($locale->value);
        foreach (['projection_range', 'point_estimate'] as $key) {
            $line = $translator->get('forecasting::forecast.'.$key);
            expect($line)->toBeString()->not->toBe('forecasting::forecast.'.$key);
            if ($locale !== Locale::En) {
                expect($line)->not->toBe($english[$key]);
            }
        }
    }
});
