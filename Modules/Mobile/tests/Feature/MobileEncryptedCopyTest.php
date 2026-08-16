<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

/*
 * MOBILE-01 — turned GREEN in Plan 04 (15-04) Task 3.
 *
 * Proves the Phase 14 at-rest encryption boundary (SensitiveColumnCodec +
 * GdkKeyringService, consumed UNCHANGED — no mobile-specific crypto) applies
 * to a row written through a Mobile-scoped fixture against the single
 * reconciled on-device DB connection (Task 2). Writes go through
 * `SensitiveColumnCodec::encryptAttrs()` directly, mirroring the direct-write
 * (import) path's own idiom rather than reusing Ledger's `RecordTransactions`
 * action — the Mobile module owns this fixture, not a cross-module action
 * call. `counterparties` is the D-02b-registry table exercised here
 * (`display_name`/`iban`/`merchant_name` are all SensitiveFieldRegistry
 * columns) since its schema is the simplest registry-covered table to
 * construct a fixture row for.
 *
 * T-15-07 (stolen phone reading the on-device DB file): a raw query against
 * the table shows ciphertext, never plaintext.
 * T-15-09 (plaintext leak when KEK absent): a withheld/locked session keeps
 * `decryptRow()` a pass-through — ciphertext in, ciphertext out, never a
 * thrown exception, never a partial leak.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'mobile-crypto-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Session $session */
    $this->session = app(Session::class);
    (new LockStateManager)->unlock($this->session, str_repeat('k', 32));

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $this->user->id, $this->session);
});

it('stores sensitive columns as ciphertext on-device, decrypting only with an unlocked KEK/session', function (): void {
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $attrs = $codec->encryptAttrs('counterparties', [
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'mobile-crypto-merchant-'.bin2hex(random_bytes(4)),
        'display_name' => 'Albert Heijn Mobile',
        'iban' => 'NL91ABNA0417164300',
        'merchant_name' => 'Albert Heijn',
        'created_at' => now(),
        'updated_at' => now(),
    ], (int) $this->user->id, $this->session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('counterparties')->insert($attrs);

    $stored = $db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->first();

    // Ciphertext at rest — a raw read off the on-device DB file must never
    // show plaintext content (T-15-07).
    expect($stored->display_name)->not->toBe('Albert Heijn Mobile');
    expect($stored->iban)->not->toBe('NL91ABNA0417164300');
    expect($stored->merchant_name)->not->toBe('Albert Heijn');

    // Plaintext only once the KEK/session is released — the SAME Phase 14
    // codec + keyring the desktop peer already uses, consumed unchanged.
    $decrypted = $codec->decryptRow('counterparties', (array) $stored, (int) $this->user->id, $this->session);
    expect($decrypted['display_name'])->toBe('Albert Heijn Mobile');
    expect($decrypted['iban'])->toBe('NL91ABNA0417164300');
    expect($decrypted['merchant_name'])->toBe('Albert Heijn');
});

it('keeps sensitive columns ciphertext when the session is withheld — no plaintext leak on a locked device', function (): void {
    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    $attrs = $codec->encryptAttrs('counterparties', [
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'mobile-crypto-locked-'.bin2hex(random_bytes(4)),
        'display_name' => 'Locked Merchant',
        'iban' => 'NL02ABNA0123456789',
        'merchant_name' => 'Locked Merchant BV',
        'created_at' => now(),
        'updated_at' => now(),
    ], (int) $this->user->id, $this->session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('counterparties')->insert($attrs);

    // Withhold the KEK — mirrors the desktop idle-lock trigger, or a
    // stolen phone found locked (T-15-09).
    app(AppLockKeyService::class)->withhold($this->session);

    $stored = $db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->first();

    $decrypted = $codec->decryptRow('counterparties', (array) $stored, (int) $this->user->id, $this->session);

    // No plaintext leak, which is the property that matters on a stolen
    // locked phone. The value now comes back BLANK rather than as the stored
    // ciphertext: leaking nothing beats leaking base64, and rendering base64
    // to a user is what this codec was doing on a device whose keyring an app
    // update had wiped. Still never a thrown exception.
    expect($decrypted['display_name'])->toBe('');
    expect($decrypted['display_name'])->not->toBe('Locked Merchant');
    expect($decrypted['iban'])->not->toBe('NL02ABNA0123456789');
    expect($decrypted['merchant_name'])->not->toBe('Locked Merchant BV');
});
