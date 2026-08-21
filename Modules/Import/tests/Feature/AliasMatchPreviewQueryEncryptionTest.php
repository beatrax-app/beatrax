<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// The preview both counts and displays. Reading the raw builder gave it
// ciphertext for each, so the count answered a question about base64 and
// the sample rows carried that base64 into a public Livewire property.

function ampeUser(): User
{
    return User::query()->create([
        'username' => 'ampe-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function ampeAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN ampe',
        'slug' => 'ampe-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL16ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

function ampeImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ampe-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'ampe-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);
}

function ampeSeed(
    User $user,
    Account $account,
    ImportRun $run,
    SensitiveColumnCodec $codec,
    Session $session,
    int $rowIndex,
    ?string $description,
    string $counterpartyName,
): void {
    DB::table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-25',
        'booked_at' => '2026-05-25 10:00:00',
        'value_date' => '2026-05-25',
        'amount_minor' => -100 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -100 - $rowIndex,
        'settled_currency' => 'EUR',
        'counterparty_name' => $codec->encryptValue('transactions', 'counterparty_name', $counterpartyName, $user->id, $session),
        'counterparty_normalized' => 'ampe-'.$rowIndex,
        'normalization_version' => 1,
        'description' => $description === null
            ? null
            : $codec->encryptValue('transactions', 'description', $description, $user->id, $session),
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'source_ref' => 'ampe-'.$rowIndex,
        'fingerprint' => hash('sha256', 'ampe-'.$user->id.'-'.$rowIndex),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => '2026-05-25 10:00:00',
        'updated_at' => '2026-05-25 10:00:00',
    ]);
}

it('counts matches against the decrypted description, not the stored ciphertext', function (): void {
    $user = ampeUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = ampeAccount($user);
    $run = ampeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    for ($i = 0; $i < 3; $i++) {
        ampeSeed($user, $account, $run, $codec, $session, $i, 'BCK*SHELL PIETER NIEUW *012'.$i, 'Shell Nederland');
    }
    ampeSeed($user, $account, $run, $codec, $session, 9, 'ALBERT HEIJN 1234', 'Albert Heijn');

    // The fixture is only meaningful if the column really holds ciphertext.
    $stored = DB::table('transactions')->where('user_id', $user->id)->value('description');
    expect($stored)->toBeString()->not->toContain('SHELL');

    $result = app(AliasMatchPreviewQuery::class)->preview('shell', $user->id);

    expect($result->total)->toBe(3);
})->group('AliasMatchPreviewQueryEncryption');

it('hands back decrypted sample rows, never the stored ciphertext', function (): void {
    $user = ampeUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = ampeAccount($user);
    $run = ampeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    ampeSeed($user, $account, $run, $codec, $session, 0, 'BCK*SHELL PIETER NIEUW *0123', 'Shell Nederland');

    $result = app(AliasMatchPreviewQuery::class)->preview('shell', $user->id);

    expect($result->first5)->toHaveCount(1);
    $row = $result->first5[0];
    expect($row->description)->toBe('BCK*SHELL PIETER NIEUW *0123');
    expect($row->counterparty_name)->toBe('Shell Nederland');
})->group('AliasMatchPreviewQueryEncryption');

it('matches on the decrypted counterparty_name when the description is null', function (): void {
    $user = ampeUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = ampeAccount($user);
    $run = ampeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    ampeSeed($user, $account, $run, $codec, $session, 0, null, 'Shell Nederland');

    $result = app(AliasMatchPreviewQuery::class)->preview('shell', $user->id);

    expect($result->total)->toBe(1);
    expect($result->first5[0]->counterparty_name)->toBe('Shell Nederland');
})->group('AliasMatchPreviewQueryEncryption');

// `decrypted: false` covers two states and only one of them is unreadable. An
// import run under a locked app-lock writes plaintext on an encrypted user
// (encryptAttrs is a documented pass-through), and treating that as ciphertext
// reported "0 transactions match" for a pattern that does match.
it('counts a plaintext row an encryption-enabled user still holds', function (): void {
    $user = ampeUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = ampeAccount($user);
    $run = ampeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    ampeSeed($user, $account, $run, $codec, $session, 0, 'BCK*SHELL PIETER NIEUW *0123', 'Shell Nederland');

    DB::table('transactions')->where('user_id', $user->id)->update([
        'description' => 'BCK*SHELL PIETER NIEUW *0123',
        'counterparty_name' => 'Shell Nederland',
    ]);

    $result = app(AliasMatchPreviewQuery::class)->preview('shell', $user->id);

    expect($result->total)->toBe(1)
        ->and($result->first5[0]->description)->toBe('BCK*SHELL PIETER NIEUW *0123');
})->group('AliasMatchPreviewQueryEncryption');

it('still drops a row whose ciphertext no epoch in this keyring opens', function (): void {
    $user = ampeUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = ampeAccount($user);
    $run = ampeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    ampeSeed($user, $account, $run, $codec, $session, 0, 'BCK*SHELL PIETER NIEUW *0123', 'Shell Nederland');

    DB::table('transactions')->where('user_id', $user->id)->update([
        'description' => base64_encode(random_bytes(48)),
    ]);

    expect(app(AliasMatchPreviewQuery::class)->preview('shell', $user->id)->total)->toBe(0);
})->group('AliasMatchPreviewQueryEncryption');

// The preview exists to predict userGeneralizedMatch(), which anchors to whole
// tokens. A substring test here promised matches the alias would never make.
it('predicts the matcher s token boundary rather than any substring', function (): void {
    $user = ampeUser();
    $session = $this->enablesEncryptionForUser($user);
    $account = ampeAccount($user);
    $run = ampeImportRun($user);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    ampeSeed($user, $account, $run, $codec, $session, 0, 'Europese incasso internet en mobiel', 'KPN');

    expect(app(AliasMatchPreviewQuery::class)->preview('obi', $user->id)->total)->toBe(0);
})->group('AliasMatchPreviewQueryEncryption');
