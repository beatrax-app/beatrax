<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'mobile-crypto-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Session $session */
    $this->session = app(Session::class);
    AppLockTestHarness::unlock($this->session, str_repeat('k', 32));

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $this->user->id, $this->session);
});

// No mobile-specific crypto: the desktop's own codec and keyring are consumed
// unchanged. counterparties is the table exercised because its schema is the
// simplest registry-covered one to build a fixture row for.

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

    // A raw read off the on-device DB file must never show plaintext.
    expect($stored->display_name)->not->toBe('Albert Heijn Mobile');
    expect($stored->iban)->not->toBe('NL91ABNA0417164300');
    expect($stored->merchant_name)->not->toBe('Albert Heijn');

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

    // Withholding the KEK is the desktop idle-lock trigger, or a phone found locked.
    app(AppLockKeyService::class)->withhold($this->session);

    $stored = $db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->first();

    $decrypted = $codec->decryptRow('counterparties', (array) $stored, (int) $this->user->id, $this->session);

    // The value comes back blank rather than as the stored ciphertext: leaking
    // nothing beats leaking base64, and rendering base64 to a user is what this
    // codec did on a device whose keyring an app update had wiped. Still never a
    // thrown exception.
    expect($decrypted['display_name'])->toBe('');
    expect($decrypted['display_name'])->not->toBe('Locked Merchant');
    expect($decrypted['iban'])->not->toBe('NL02ABNA0123456789');
    expect($decrypted['merchant_name'])->not->toBe('Locked Merchant BV');
});
