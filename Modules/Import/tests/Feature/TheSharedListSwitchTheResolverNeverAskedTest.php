<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Enums\CommunitySetting;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\MerchantNameResolver;

// "Use the shared merchant list" is the privacy opt-out for the community
// corpus, and the corpus tiers of the resolver are its only consumer. An arm
// that still answers with the switch off is the opt-out leaking.

function sharedListGateUser(string $username): User
{
    return User::create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function sharedListGateCorpusRow(string $pattern, string $generalizedPattern, string $name): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => $pattern,
        'generalized_pattern' => $generalizedPattern,
        'name' => $name,
        'category' => null,
        'region' => 'NL',
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

function sharedListGateSwitchOff(User $user): void
{
    $user->community_settings = [CommunitySetting::UseSharedList->value => false];
    $user->save();
}

function sharedListGateResolver(): MerchantNameResolver
{
    return app(MerchantNameResolver::class);
}

beforeEach(function (): void {
    $this->user = sharedListGateUser('shared-list-gate-user');
});

it('keeps the corpus-exact tier silent for a reader who switched the shared list off', function (): void {
    sharedListGateCorpusRow('SHELL PIETER X', 'shell pieter', 'Shell — Pieter Nieuwlandstraat');
    sharedListGateSwitchOff($this->user);

    expect(sharedListGateResolver()->resolve('SHELL PIETER X', $this->user->id))->toBeNull();
});

it('keeps the corpus-generalized tier silent for a reader who switched the shared list off', function (): void {
    sharedListGateCorpusRow('BCK*SHELL ROOT', 'shell pieter', 'Shell — Pieter (generalized)');
    sharedListGateSwitchOff($this->user);

    expect(sharedListGateResolver()->resolve('BCK*SHELL PIETER NIEUW *0123', $this->user->id))->toBeNull();
});

it('keeps the corpus-regex tier silent for a reader who switched the shared list off', function (): void {
    sharedListGateCorpusRow('regex:\bICA\b', '', 'ICA');
    sharedListGateSwitchOff($this->user);

    expect(sharedListGateResolver()->resolve('ICA MAXI STOCKHOLM', $this->user->id))->toBeNull();
});

it('still answers from the readers own aliases with the shared list off', function (): void {
    sharedListGateCorpusRow('AH 1234 T9999', 'albert heijn', 'Community AH');
    sharedListGateSwitchOff($this->user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'AH 1234 T9999',
        'generalized_pattern' => 'albert heijn',
        'friendly_name' => 'My Albert Heijn',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $resolver = sharedListGateResolver();

    expect($resolver->resolve('AH 1234 T9999', $this->user->id))->toBe('My Albert Heijn')
        ->and($resolver->resolve('BEA ALBERT HEIJN 4411', $this->user->id))->toBe('My Albert Heijn');
});

it('consults the corpus for a reader who never opened the settings panel', function (): void {
    sharedListGateCorpusRow('SHELL PIETER X', 'shell pieter', 'Shell — Pieter Nieuwlandstraat');

    expect(sharedListGateResolver()->resolve('SHELL PIETER X', $this->user->id))
        ->toBe('Shell — Pieter Nieuwlandstraat');
});

it('gates on the reader whose id it was given, not on whoever switched theirs off', function (): void {
    sharedListGateCorpusRow('SHELL PIETER X', 'shell pieter', 'Shell — Pieter Nieuwlandstraat');
    sharedListGateSwitchOff($this->user);

    $other = sharedListGateUser('shared-list-gate-other');
    $resolver = sharedListGateResolver();

    expect($resolver->resolve('SHELL PIETER X', $other->id))->toBe('Shell — Pieter Nieuwlandstraat')
        ->and($resolver->resolve('SHELL PIETER X', $this->user->id))->toBeNull();
});

it('stops consulting the corpus as soon as the reader switches the shared list off', function (): void {
    sharedListGateCorpusRow('SHELL PIETER X', 'shell pieter', 'Shell — Pieter Nieuwlandstraat');
    $resolver = sharedListGateResolver();

    expect($resolver->resolve('SHELL PIETER X', $this->user->id))->toBe('Shell — Pieter Nieuwlandstraat');

    sharedListGateSwitchOff($this->user);

    expect($resolver->resolve('SHELL PIETER X', $this->user->id))->toBeNull();
});
