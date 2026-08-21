<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
use Modules\Sync\Public\Services\BlindIndexCodec;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#an-encrypted-column-rendered-to-the-reader-as-ciphertext
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */

// Three separate commits on one branch fixed the same defect, and all three
// were found by a human holding a phone rather than by the suite. The shape is
// always a read of a registered column that never reached the codec, and it is
// invisible to a source scan: the triage queue selected `*` through the raw
// builder and hydrated models from it, so the leaking columns are not named
// anywhere in the file, and the file already carried three codec markers for
// the transaction descriptions it did decrypt.
//
// So this guard is behavioural. It turns encryption on for real through the
// production enable path, renders the screens over the ciphertext that produces,
// and asserts that the exact bytes sitting in the database do not appear in the
// response body. Asserting on the stored value rather than on "does this look
// like base64" is what makes it precise enough to keep.

const RCG_MERCHANT = 'Qwyxberg Sentinel Coffee';

const RCG_DESCRIPTION = 'QWYXBERG SENTINEL COFFEE DELFT';

const RCG_MYSTERY_DESCRIPTION = 'ZK*VONDELPARK SENTINEL 8842';

const RCG_UNNAMED_DESCRIPTION = 'RENTE SENTINEL QWYX 0021';

const RCG_CP_IBAN = 'NL44RABO0123456789';

// What the triage card is supposed to print for RCG_CP_IBAN. The card masks the
// middle of the IBAN, so only six characters of the stored value ever reach the
// page — which is precisely why an absent-ciphertext assertion cannot see this
// leak on its own, and why the masked plaintext is asserted as well. The
// shipped bug rendered `7F · ·· HUX5 ···· ···· ==`: a mask over ciphertext.
const RCG_CP_IBAN_MASKED = 'NL · ·· RABO ···· ···· 89';

const RCG_TX_IBAN = 'NL91ABNA0417164300';

const RCG_TX_NOTE = 'qwyx-sentinel-transaction-note';

const RCG_TAX_NOTE = 'qwyx-sentinel-tax-note';

const RCG_SPLIT_NOTE = 'qwyx-sentinel-split-note';

const RCG_NOTIFICATION_TITLE = 'Qwyx sentinel notification title';

const RCG_NOTIFICATION_BODY = 'Qwyx sentinel notification body';

const RCG_SERIES_NAME = 'Qwyxberg Sentinel Subscription';

const RCG_APPROVED_SERIES_NAME = 'Vondelgracht Sentinel Gym';

const RCG_CASH_MERCHANT = 'Kiosk Sentinel Qwyx';

const RCG_CASH_NOTE = 'qwyx-sentinel-cash-note';

const RCG_RECEIPT_INCOMING = 'Qwyxberg Sentinel Coffee BV';

/**
 * Not a value the reader owns — the crypto layer's own vocabulary. It reaches a
 * screen when an exception message is caught and printed as if it were copy,
 * which puts an internal class name and the reader's own user id where the
 * instruction "unlock the app and try again" belongs.
 *
 * @return list<string>
 */
function rcgMachineryMarkers(): array
{
    return [
        'BlindIndexCodec',
        'SensitiveColumnCodec',
        'OpLogFieldCrypto',
        'sodium_',
        'Modules\\Sync\\',
    ];
}

/**
 * @param  list<string>  $markers
 * @return list<string>
 */
function rcgMachineryLeaks(string $html, array $markers): array
{
    $body = rcgSearchableBody($html);

    $found = [];
    foreach ($markers as $marker) {
        if (str_contains($body, $marker)) {
            $found[] = $marker;
        }
    }

    return $found;
}

/**
 * Read from the registry, not copied. `columns()` and `blindIndexColumns()` are
 * deliberately separate — one is an instruction that applies AEAD, the other
 * describes columns AEAD must never touch — but a guard needs both, and one
 * place to add a column is the whole point of the second accessor.
 *
 * @return array<string, list<string>> table => columns holding a keyed digest
 */
function rcgBlindIndexColumns(): array
{
    $byTable = [];
    foreach (array_keys(SensitiveFieldRegistry::blindIndexColumns()) as $pair) {
        [$table, $column] = explode('.', $pair, 2);
        $byTable[$table][] = $column;
    }

    return $byTable;
}

/**
 * @return array<string, list<string>> table => columns, from SensitiveFieldRegistry
 */
function rcgSensitiveColumns(): array
{
    $byTable = [];
    foreach (SensitiveFieldRegistry::columns() as $pair) {
        [$table, $column] = explode('.', $pair, 2);
        $byTable[$table][] = $column;
    }

    return $byTable;
}

function rcgSeedWorld(User $user): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();
    $now = CarbonImmutable::parse('2026-08-14 09:00:00');

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Sentinel ASN',
        'slug' => 'rcg-sentinel-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456780',
        'default_currency' => 'EUR',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $importRunId = $connection->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rcg-sentinel.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => $now,
        'confirmed_at' => $now,
        'status' => 'confirmed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $counterpartyId = $connection->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'unknown',
        'slug' => 'rcg-sentinel-unknown',
        'display_name' => RCG_MERCHANT,
        'merchant_name' => RCG_MERCHANT,
        'iban' => RCG_CP_IBAN,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $transactionId = $connection->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'counterparty_id' => $counterpartyId,
        'type' => 'expense',
        'posted_at' => '2026-08-14',
        'booked_at' => '2026-08-14 09:00:00',
        'value_date' => '2026-08-14',
        'amount_minor' => -1850,
        'currency' => 'EUR',
        'settled_amount_minor' => -1850,
        'settled_currency' => 'EUR',
        'description' => RCG_DESCRIPTION,
        'note' => RCG_TX_NOTE,
        'counterparty_name' => RCG_MERCHANT,
        'counterparty_iban' => RCG_TX_IBAN,
        'counterparty_normalized' => 'qwyxberg sentinel coffee',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => 1,
        'fingerprint' => str_pad('1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'payment_type' => 'pin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // A second row with no counterparty and no merchant match: this is what
    // /community/mystery-merchants lists, and the page that offers to publish
    // a description to a shared corpus is the one that listed nineteen base64
    // blobs and invited the reader to send them.
    $connection->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'counterparty_id' => null,
        'type' => 'expense',
        'posted_at' => '2026-08-12',
        'booked_at' => '2026-08-12 11:30:00',
        'value_date' => '2026-08-12',
        'amount_minor' => -4200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4200,
        'settled_currency' => 'EUR',
        'description' => RCG_MYSTERY_DESCRIPTION,
        'counterparty_name' => null,
        'counterparty_normalized' => 'zk vondelpark sentinel 8842',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => 2,
        'fingerprint' => str_pad('2', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'payment_type' => 'pin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // The sentinel is stored verbatim — the sweep skips it on purpose,
    // because it records the absence of a counterparty rather than naming one.
    // It is still a machine token in a column the reader must never be shown,
    // so the census carries it beside the digests.
    $connection->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'counterparty_id' => null,
        'type' => 'expense',
        'posted_at' => '2026-08-10',
        'booked_at' => '2026-08-10 08:15:00',
        'value_date' => '2026-08-10',
        'amount_minor' => -190,
        'currency' => 'EUR',
        'settled_amount_minor' => -190,
        'settled_currency' => 'EUR',
        'description' => RCG_UNNAMED_DESCRIPTION,
        'counterparty_name' => null,
        'counterparty_normalized' => SensitiveFieldRegistry::blindIndexSentinel(),
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $importRunId,
        'source_row_index' => 3,
        'fingerprint' => str_pad('3', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'payment_type' => 'pin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $categoryId = $connection->table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'Sentinel Category',
        'slug' => 'rcg-sentinel-category',
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('transaction_splits')->insert([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => -1850,
        'settled_currency' => 'EUR',
        'note' => RCG_SPLIT_NOTE,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('tax_transaction_tags')->insert([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'deduction_category_id' => null,
        'tax_year_override' => null,
        'note' => RCG_TAX_NOTE,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('merchants')->insert([
        'user_id' => $user->id,
        'name' => RCG_MERCHANT,
        'normalized_name' => 'qwyxberg sentinel coffee',
        'default_category_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => RCG_DESCRIPTION,
        'generalized_pattern' => 'qwyxberg',
        'friendly_name' => RCG_MERCHANT,
        'merged_from' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // /cash reads source_format = 'manual' only.
    $cashTransactionId = $connection->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'counterparty_id' => null,
        'type' => 'expense',
        'posted_at' => '2026-08-13',
        'booked_at' => '2026-08-13 16:40:00',
        'value_date' => '2026-08-13',
        'amount_minor' => -650,
        'currency' => 'EUR',
        'settled_amount_minor' => -650,
        'settled_currency' => 'EUR',
        'description' => 'CASH SENTINEL QWYX KIOSK',
        'note' => RCG_CASH_NOTE,
        'counterparty_name' => RCG_CASH_MERCHANT,
        'counterparty_normalized' => 'kiosk sentinel qwyx',
        'normalization_version' => 1,
        'source_format' => 'manual',
        'import_run_id' => $importRunId,
        'source_row_index' => 0,
        'fingerprint' => str_pad('4', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'payment_type' => 'cash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // A chain link so the drawer has a leg to draw, and a held enrichment
    // conflict so the receipt toast has a prompt to show. Both render a
    // counterparty name that is ciphertext in the row they read it from.
    $connection->table('chain_links')->insert([
        'user_id' => $user->id,
        'from_transaction_id' => $transactionId,
        'to_transaction_id' => $cashTransactionId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 0.900,
        'resolver' => 'auto',
        'evidence' => json_encode(['reason' => 'rcg-fixture'], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // stored_value/incoming_value hold DECRYPTED values by design — the prompt
    // must never render ciphertext — so this row is the case that proves the
    // exception is still working rather than a leak waiting to happen.
    $connection->table('pending_enrichment_conflicts')->insert([
        'user_id' => $user->id,
        'transaction_id' => $transactionId,
        'field_name' => 'counterparty_name',
        'stored_value' => RCG_MERCHANT,
        'incoming_value' => RCG_RECEIPT_INCOMING,
        'incoming_source_format' => 'receipt',
        'import_run_id' => $importRunId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => RCG_SERIES_NAME,
        'display_name_override' => null,
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1850,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1850,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => '2026-09-14',
        'cluster_key' => 'rcg-sentinel-cluster',
        'cluster_counterparty_key' => 'qwyxberg sentinel coffee',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // /recurring lists approved series; /calendar walks their occurrences.
    $approvedSeriesId = $connection->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => RCG_APPROVED_SERIES_NAME,
        'display_name_override' => null,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -2500,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -2500,
        'variance_tolerance_percent' => 25,
        'next_expected_at' => '2026-09-14',
        'cluster_key' => 'rcg-sentinel-cluster-approved',
        'cluster_counterparty_key' => 'vondelgracht sentinel gym',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $connection->table('recurring_series_occurrences')->insert([
        'user_id' => $user->id,
        'recurring_series_id' => $approvedSeriesId,
        'transaction_id' => $transactionId,
        'observed_at' => '2026-08-14',
        'observed_amount_minor' => -1850,
        'observed_currency' => 'EUR',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// notifications is in SensitiveFieldRegistry but NOT in
// PreMigrationSnapshot::PROJECTION_COLUMNS, so the enable-time sweep never
// touches it — the rows are encrypted by NotificationWriter on write instead.
// Seeding after the sweep, through the same codec that writer uses, is what a
// notification raised on an already-encrypted device actually looks like.
function rcgSeedNotification(User $user, Session $session): void
{
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::parse('2026-08-14 10:00:00');

    $attrs = $codec->encryptAttrs('notifications', [
        'title' => RCG_NOTIFICATION_TITLE,
        'body' => RCG_NOTIFICATION_BODY,
        'params' => json_encode(['merchant' => RCG_MERCHANT], JSON_THROW_ON_ERROR),
        'trigger_type' => 'payment_reminder',
    ], (int) $user->id, $session);

    $db->connection()->table('notifications')->insert([
        'id' => hash('sha256', 'rcg-sentinel-notification'),
        'user_id' => $user->id,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => $attrs['title'],
        'body' => $attrs['body'],
        'params' => $attrs['params'],
        'trigger_type' => $attrs['trigger_type'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Every value a reader must never see, read straight back out of the database
 * after encryption is on: AEAD ciphertext for the registered columns, keyed
 * digests for the blind-index ones.
 *
 * @return array<string, string> "{table}.{column}#{n}" => stored value
 */
function rcgUnreadableCensus(int $userId): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $census = [];
    $all = array_merge_recursive(rcgSensitiveColumns(), rcgBlindIndexColumns());

    foreach ($all as $table => $columns) {
        $rows = $connection->table($table)->where('user_id', $userId)->get();
        foreach ($rows as $index => $row) {
            foreach ($columns as $column) {
                /** @var mixed $value */
                $value = ((array) $row)[$column] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $census["{$table}.{$column}#{$index}"] = $value;
            }
        }
    }

    return $census;
}

/**
 * A leak into a public Livewire property reaches the browser inside the
 * wire:snapshot JSON, where the value's slashes are backslash-escaped and its
 * quotes entified. Normalising both back means one substring search covers the
 * page text and the browser payload alike.
 */
function rcgSearchableBody(string $html): string
{
    $unescaped = str_replace(['\\/', '\\"'], ['/', '"'], $html);

    return html_entity_decode($unescaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Long enough that a run of it cannot occur by chance in base64 or hex, short
// enough that the ordinary display truncations still leave one intact. A
// Str::limit() over ciphertext prints a shorter blob, not a readable one.
const RCG_FRAGMENT_LENGTH = 16;

/**
 * @param  array<string, string>  $census
 * @return list<string> one entry per stored value found in the body, whole or truncated
 */
function rcgLeaks(string $html, array $census): array
{
    $body = rcgSearchableBody($html);

    $leaked = [];
    foreach ($census as $label => $stored) {
        if (str_contains($body, $stored)) {
            $leaked[] = "{$label}: the whole stored value";

            continue;
        }
        $length = strlen($stored);
        for ($offset = 0; $offset + RCG_FRAGMENT_LENGTH <= $length; $offset++) {
            $fragment = substr($stored, $offset, RCG_FRAGMENT_LENGTH);
            if (str_contains($body, $fragment)) {
                $leaked[] = "{$label}: a {$length}-character stored value, truncated to '{$fragment}…'";

                break;
            }
        }
    }

    return $leaked;
}

/**
 * `html()` alone is not what a Livewire component sends. A public property
 * reaches the browser inside the wire:snapshot payload whether or not the view
 * prints it, and PaletteSearchEndpoint is a hidden container whose results live
 * only there. Both halves are the browser's copy, so both are searched.
 *
 * @param  list<string>  $properties
 */
function rcgLivewirePayload(Testable $component, array $properties): string
{
    $parts = [$component->html()];
    foreach ($properties as $property) {
        $parts[] = (string) json_encode($component->get($property));
    }

    return implode("\n", $parts);
}

/**
 * @param  list<string>  $mustBeVisible
 * @return list<string>
 */
function rcgMissing(string $html, array $mustBeVisible): array
{
    $body = rcgSearchableBody($html);

    $missing = [];
    foreach ($mustBeVisible as $expected) {
        if (! str_contains($body, $expected)) {
            $missing[] = $expected;
        }
    }

    return $missing;
}

/**
 * The two halves are complementary and both are load bearing. The absence half
 * catches a stored value printed whole or truncated. It cannot catch a value a
 * view masks or reformats first, because only a few characters of it survive —
 * the shipped triage bug put ciphertext through an IBAN mask and printed six.
 * The presence half is what catches that one.
 *
 * @param  array<string, string>  $census
 * @param  list<string>  $mustBeVisible
 */
function rcgExpectReadable(string $screen, string $html, array $census, array $mustBeVisible): void
{
    $machinery = rcgMachineryLeaks($html, rcgMachineryMarkers());

    expect($machinery)->toBe([], implode("\n", [
        "{$screen} printed the crypto layer's own vocabulary to the reader:",
        ...$machinery,
        '',
        'This is what a caught exception message looks like once it is treated as',
        'copy. It names an internal class and, in the blind-index case, the',
        "reader's own user id, in the place where the instruction they can act on",
        'belongs. Catch the specific exception and render a sentence the reader',
        'can do something about; log the message instead of printing it.',
    ]));

    $leaked = rcgLeaks($html, $census);

    expect($leaked)->toBe([], implode("\n", [
        "{$screen} rendered a value as it is stored, not as the reader needs it:",
        ...$leaked,
        '',
        'Those columns hold XChaCha20-Poly1305 ciphertext, or a keyed HMAC in the',
        'case of a blind index. Neither is readable and neither raises when it is',
        'printed — the page returns 200 and the card simply shows base64. Read the',
        'column through SensitiveColumnCodec, decrypting BEFORE Model::hydrate()',
        'rather than after, and never put a stored matching key on a screen at all.',
    ]));

    $missing = rcgMissing($html, $mustBeVisible);

    expect($missing)->toBe([], implode("\n", [
        "{$screen} did not show the plaintext it exists to show:",
        ...$missing,
        '',
        'The likely cause is the same defect the assertion above looks for, in the',
        'form that assertion cannot see: a view that masks, truncates or reformats',
        'a value still prints when handed ciphertext, it just prints the wrong few',
        'characters, so the stored bytes never appear in the body to be found. The',
        'other possibility is a fixture that stopped reaching this screen — an',
        'empty state passes the absence half too, which is what makes this half',
        'the thing standing between a real guard and a green one that checks',
        'nothing.',
    ]));
}

beforeEach(function (): void {
    $this->rcgUser = User::query()->create([
        'username' => 'rcg-sentinel-user',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    rcgSeedWorld($this->rcgUser);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    $this->rcgSession = $session;

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);
    $migration->migrate($this->rcgUser, $session);

    rcgSeedNotification($this->rcgUser, $session);

    $this->rcgCensus = rcgUnreadableCensus((int) $this->rcgUser->id);

    $this->actingAs($this->rcgUser);
});

// Without this every screen assertion below is vacuously green: decrypting a
// plaintext value is a documented no-op, so a fixture that failed to encrypt
// would let a completely broken read path pass on every screen at once.
it('actually holds ciphertext and keyed digests at rest before a single screen is rendered', function (): void {
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $notCiphertext = [];
    foreach (rcgSensitiveColumns() as $table => $columns) {
        $rows = $this->app->make(DatabaseManager::class)->connection()
            ->table($table)->where('user_id', $this->rcgUser->id)->get();
        foreach ($rows as $index => $row) {
            foreach ($columns as $column) {
                /** @var mixed $value */
                $value = ((array) $row)[$column] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $result = $codec->decryptValue($table, $column, $value, (int) $this->rcgUser->id, $this->rcgSession);
                if (! $result['decrypted']) {
                    $notCiphertext[] = "{$table}.{$column}#{$index}";
                }
            }
        }
    }

    expect($notCiphertext)->toBe([]);

    $notDerived = [];
    foreach (rcgBlindIndexColumns() as $table => $columns) {
        $rows = $this->app->make(DatabaseManager::class)->connection()
            ->table($table)->where('user_id', $this->rcgUser->id)->get();
        foreach ($rows as $index => $row) {
            foreach ($columns as $column) {
                /** @var mixed $value */
                $value = ((array) $row)[$column] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }
                if (! BlindIndexCodec::looksDerived($value) && $value !== SensitiveFieldRegistry::blindIndexSentinel()) {
                    $notDerived[] = "{$table}.{$column}#{$index} = {$value}";
                }
            }
        }
    }

    expect($notDerived)->toBe([]);

    expect($this->rcgCensus)->not->toBe([]);
});

it('never renders a stored sensitive value on /counterparties/triage', function (): void {
    $response = $this->get('/counterparties/triage');
    $response->assertOk();

    rcgExpectReadable('/counterparties/triage', $response->getContent(), $this->rcgCensus, [
        RCG_CP_IBAN_MASKED,
        RCG_DESCRIPTION,
    ]);
});

it('never renders a stored sensitive value on /community/mystery-merchants', function (): void {
    $response = $this->get('/community/mystery-merchants');
    $response->assertOk();

    rcgExpectReadable('/community/mystery-merchants', $response->getContent(), $this->rcgCensus, [
        RCG_MYSTERY_DESCRIPTION,
    ]);
});

it('never renders a stored sensitive value on /transactions', function (): void {
    $response = $this->get('/transactions');
    $response->assertOk();

    rcgExpectReadable('/transactions', $response->getContent(), $this->rcgCensus, [
        RCG_MERCHANT,
    ]);
});

it('never renders a stored sensitive value on a transaction detail page', function (): void {
    $transactionId = $this->app->make(DatabaseManager::class)->connection()
        ->table('transactions')->where('user_id', $this->rcgUser->id)
        ->where('source_row_index', 1)->value('id');

    $response = $this->get("/transactions/{$transactionId}");
    $response->assertOk();

    rcgExpectReadable('/transactions/{id}', $response->getContent(), $this->rcgCensus, [
        RCG_MERCHANT,
        RCG_TX_NOTE,
        RCG_SPLIT_NOTE,
    ]);
});

it('never renders a stored sensitive value on /notifications', function (): void {
    $response = $this->get('/notifications');
    $response->assertOk();

    rcgExpectReadable('/notifications', $response->getContent(), $this->rcgCensus, [
        RCG_NOTIFICATION_TITLE,
        RCG_NOTIFICATION_BODY,
    ]);
});

it('never renders a stored sensitive value on /recurring/review', function (): void {
    $response = $this->get('/recurring/review');
    $response->assertOk();

    rcgExpectReadable('/recurring/review', $response->getContent(), $this->rcgCensus, [
        RCG_SERIES_NAME,
    ]);
});

it('never renders a stored sensitive value on /counterparties', function (): void {
    $response = $this->get('/counterparties');
    $response->assertOk();

    rcgExpectReadable('/counterparties', $response->getContent(), $this->rcgCensus, [
        RCG_MERCHANT,
    ]);
});

// The alias match preview is a keystroke away rather than a page load, and it
// is the one that put ciphertext into a PUBLIC Livewire property — on the wire
// as well as on the screen — while matching the pattern against that same
// ciphertext, so the count it reported was wrong too.
it('never renders a stored sensitive value in the alias match preview', function (): void {
    $aliasId = $this->app->make(DatabaseManager::class)->connection()
        ->table('merchant_aliases')->where('user_id', $this->rcgUser->id)->value('id');

    $component = Livewire::actingAs($this->rcgUser)
        ->test('import.aliases-settings-page')
        ->call('startEdit', $aliasId)
        ->set('editingPattern', 'qwyxberg');

    rcgExpectReadable('/settings/aliases (match preview)', $component->html(), $this->rcgCensus, [
        RCG_DESCRIPTION,
    ]);
});

it('never renders a stored sensitive value on /uncategorized', function (): void {
    $response = $this->get('/uncategorized');
    $response->assertOk();

    rcgExpectReadable('/uncategorized', $response->getContent(), $this->rcgCensus, [
        RCG_MERCHANT,
        RCG_DESCRIPTION,
        RCG_MYSTERY_DESCRIPTION,
    ]);
});

it('never renders a stored sensitive value on a counterparty profile', function (): void {
    $response = $this->get('/counterparties/rcg-sentinel-unknown');
    $response->assertOk();

    rcgExpectReadable('/counterparties/{slug}', $response->getContent(), $this->rcgCensus, [
        RCG_MERCHANT,
        RCG_DESCRIPTION,
    ]);
});

it('never renders a stored sensitive value on /tax', function (): void {
    $response = $this->get('/tax');
    $response->assertOk();

    rcgExpectReadable('/tax', $response->getContent(), $this->rcgCensus, [
        RCG_MERCHANT,
        RCG_TAX_NOTE,
    ]);
});

it('never renders a stored sensitive value on /reports', function (): void {
    $response = $this->get('/reports');
    $response->assertOk();

    rcgExpectReadable('/reports', $response->getContent(), $this->rcgCensus, [
        RCG_MERCHANT,
    ]);
});

it('never renders a stored sensitive value on a recurring series detail page', function (): void {
    // Addressed by the name the page renders, not by the clustering key: the
    // enable-time sweep recomposes cluster_key over the keyed counterparty
    // digest, so the seeded literal is gone by the time this runs.
    $seriesId = $this->app->make(DatabaseManager::class)->connection()
        ->table('recurring_series')->where('user_id', $this->rcgUser->id)
        ->where('detected_name', RCG_SERIES_NAME)->value('id');

    $response = $this->get("/recurring/series/{$seriesId}");
    $response->assertOk();

    rcgExpectReadable('/recurring/series/{id}', $response->getContent(), $this->rcgCensus, [
        RCG_SERIES_NAME,
    ]);
});

it('never renders a stored sensitive value on /cash', function (): void {
    $response = $this->get('/cash');
    $response->assertOk();

    rcgExpectReadable('/cash', $response->getContent(), $this->rcgCensus, [
        RCG_CASH_MERCHANT,
        RCG_MERCHANT,
    ]);
});

it('never renders a stored sensitive value on /recurring', function (): void {
    $response = $this->get('/recurring');
    $response->assertOk();

    rcgExpectReadable('/recurring', $response->getContent(), $this->rcgCensus, [
        RCG_APPROVED_SERIES_NAME,
    ]);
});

it('never renders a stored sensitive value on /calendar', function (): void {
    $response = $this->get('/calendar');
    $response->assertOk();

    rcgExpectReadable('/calendar', $response->getContent(), $this->rcgCensus, [
        RCG_APPROVED_SERIES_NAME,
        RCG_MERCHANT,
    ]);
});

// The widest surface of the lot: the palette searches counterparty, category
// and series names across the whole app, and every hit lands in a public
// property. A name that matched as ciphertext would find nothing; a name
// rendered as ciphertext would be shipped to the browser in the snapshot.
it('never renders a stored sensitive value through the command palette', function (): void {
    $transactionId = (int) $this->app->make(DatabaseManager::class)->connection()
        ->table('transactions')->where('user_id', $this->rcgUser->id)
        ->where('source_row_index', 1)->value('id');

    $this->app->make(SearchIndexWriterContract::class)
        ->upsertForTransaction($transactionId, (int) $this->rcgUser->id);

    $component = Livewire::actingAs($this->rcgUser)
        ->test('search.palette-search-endpoint')
        ->call('search', 'Qwyxberg');

    $payload = rcgLivewirePayload($component, ['entityHits', 'transactionHits', 'totalCount']);

    rcgExpectReadable('⌘K palette', $payload, $this->rcgCensus, [
        RCG_MERCHANT,
        RCG_SERIES_NAME,
    ]);
});

it('never renders a stored sensitive value in the chain drawer', function (): void {
    $transactionId = (int) $this->app->make(DatabaseManager::class)->connection()
        ->table('transactions')->where('user_id', $this->rcgUser->id)
        ->where('source_row_index', 1)->value('id');

    $component = Livewire::actingAs($this->rcgUser)
        ->test('chains.chain-drawer')
        ->call('open', $transactionId);

    rcgExpectReadable('chain drawer', rcgLivewirePayload($component, ['transactionId']), $this->rcgCensus, [
        RCG_MERCHANT,
        RCG_CASH_MERCHANT,
    ]);
});

// pending_enrichment_conflicts holds DECRYPTED values on purpose, so the prompt
// never has ciphertext to render. This is the case that proves that exception is
// still true rather than assuming it.
it('never renders a stored sensitive value in the receipt conflict toast', function (): void {
    $component = Livewire::actingAs($this->rcgUser)->test('receipts.receipt-conflict-toast');

    $payload = rcgLivewirePayload($component, ['receiptValue', 'csvValue', 'field']);

    rcgExpectReadable('receipt conflict toast', $payload, $this->rcgCensus, [
        RCG_MERCHANT,
        RCG_RECEIPT_INCOMING,
    ]);
});

// The counterparty picker only exists once the rule has a "reassign
// counterparty" action, and that select is the whole reason this modal reads an
// encrypted column: every option label is a decrypted display_name.
it('never renders a stored sensitive value in the rule form modal', function (): void {
    $component = Livewire::actingAs($this->rcgUser)
        ->test('categorization.rule-form-modal')
        ->call('open', null)
        ->set('actions', [[
            'id' => null,
            'type' => 'counterparty',
            'category_id' => null,
            'counterparty_id' => null,
            'note_text' => '',
            'note_mode' => 'set',
            'deduction_category_id' => null,
            'year_override_enabled' => false,
            'year_override' => null,
        ]]);

    rcgExpectReadable('rule form modal', rcgLivewirePayload($component, ['conditions', 'actions']), $this->rcgCensus, [
        RCG_MERCHANT,
    ]);
});

// The popover is handed its raw string by the import preview rather than reading
// a column, so the visible value below is the one passed in. It is here for the
// absence half: it writes a merchant alias, and a leak would be one keystroke
// from being stored as a pattern.
it('never renders a stored sensitive value in the rename-counterparty popover', function (): void {
    $component = Livewire::actingAs($this->rcgUser)
        ->test('import.rename-counterparty-popover')
        ->call('open', RCG_DESCRIPTION, 1);

    $payload = rcgLivewirePayload($component, ['raw', 'generalized', 'friendly']);

    rcgExpectReadable('rename-counterparty popover', $payload, $this->rcgCensus, [
        RCG_DESCRIPTION,
    ]);
});

// The import preview is the one screen in this file that renders nothing from a
// registered column — its rows come from the parsed file through PreviewCache,
// and never touch the database. It is here for the other shape: ImportPipeline
// catches Throwable around the normalise stage and puts $e->getMessage() into
// the row, so the one exception the blind index raises by design is printed to
// the reader, once per row, as if it were copy.
it('never prints the crypto layer\'s vocabulary on the import preview', function (): void {
    $importRunId = (int) $this->app->make(DatabaseManager::class)->connection()
        ->table('import_runs')->where('user_id', $this->rcgUser->id)->value('id');

    // The real message, from the real exception, rather than a string shaped to
    // look like one: what reaches the row is whatever this class says.
    $message = BlindIndexKeyUnavailableException::forUser((int) $this->rcgUser->id, 'counterparty-normalized')
        ->getMessage();

    $preview = new ImportPreviewResult(
        importRunId: $importRunId,
        rows: [new PreviewRowDto(
            rowIndex: 1,
            status: PreviewRowStatus::Error,
            accountId: null,
            bookedAt: '14-08-2026',
            counterpartyName: RCG_MERCHANT,
            counterpartyIban: RCG_CP_IBAN,
            description: RCG_DESCRIPTION,
            categoryName: null,
            amountMinor: -1850,
            currency: 'EUR',
            error: $message,
        )],
        accountsToName: [],
    );

    // Written straight to the cache key PreviewCache owns: that class is
    // Import\Internal and out of reach from here, while the DTOs it stores are
    // Public. If the key format ever moves, the wizard renders its expired
    // state and the visibility assertion below is what says so.
    $this->app->make(CacheRepository::class)->put(
        "import.{$importRunId}.preview",
        $preview->toArray(),
        now()->addMinutes(30),
    );

    $response = $this->get("/imports/{$importRunId}/preview");
    $response->assertOk();

    rcgExpectReadable('/imports/{id}/preview', $response->getContent(), $this->rcgCensus, [
        RCG_DESCRIPTION,
    ]);
});

// The screen cases above are only as good as the two functions they call, and a
// matcher that can never fire is the failure mode this whole branch is about.
// These three probes run the matchers against bodies built to trip them.
it('goes RED on a whole stored value printed into a body (negative probe, in-memory)', function (): void {
    $stored = $this->rcgCensus['counterparties.iban#0'];

    expect(rcgLeaks("<span class=\"triage-iban\">{$stored}</span>", $this->rcgCensus))
        ->toContain('counterparties.iban#0: the whole stored value');
});

it('goes RED on a stored value truncated before it is printed (negative probe, in-memory)', function (): void {
    $stored = $this->rcgCensus['transactions.description#0'];
    $truncated = substr($stored, 0, 24).'…';

    $leaks = rcgLeaks("<p class=\"primary\">{$truncated}</p>", $this->rcgCensus);

    expect($leaks)->not->toBe([]);
    expect(implode("\n", $leaks))->toContain('transactions.description#0');
});

it('goes RED when a screen stops showing the plaintext it exists to show (negative probe, in-memory)', function (): void {
    expect(rcgMissing('<html><body>all caught up</body></html>', [RCG_DESCRIPTION]))
        ->toBe([RCG_DESCRIPTION]);
});

// The two accessors must stay disjoint. A blind-index column appearing in
// columns() would put AEAD over a column the database matches on, and the census
// below would go on looking correct while the ledger stopped deduplicating.
it('keeps the blind-index columns out of the AEAD list, and seeds a row for each', function (): void {
    $registry = rcgSensitiveColumns();

    expect(rcgBlindIndexColumns())->not->toBe([]);

    foreach (rcgBlindIndexColumns() as $table => $columns) {
        foreach ($columns as $column) {
            expect($registry[$table] ?? [])->not->toContain($column);
            expect($this->rcgCensus)->toHaveKey("{$table}.{$column}#0");
        }
    }
});

it('goes RED on a crypto exception message printed as copy (negative probe, in-memory)', function (): void {
    $message = BlindIndexKeyUnavailableException::forUser(3, 'counterparty-normalized')->getMessage();

    expect(rcgMachineryLeaks("<span title=\"{$message}\">Fout</span>", rcgMachineryMarkers()))
        ->toContain('BlindIndexCodec');
});

it('goes RED on the _no_counterparty sentinel reaching a body (negative probe, in-memory)', function (): void {
    $sentinelKey = array_search(SensitiveFieldRegistry::blindIndexSentinel(), $this->rcgCensus, true);

    expect($sentinelKey)->not->toBeFalse();
    expect(rcgLeaks('<span class="primary">'.SensitiveFieldRegistry::blindIndexSentinel().'</span>', $this->rcgCensus))
        ->toContain("{$sentinelKey}: the whole stored value");
});
