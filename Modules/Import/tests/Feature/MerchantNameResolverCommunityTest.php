<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\MerchantNameResolver;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'resolver-community-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('returns null when no aliases and no community rows match', function (): void {
    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('SHELL PIETER X', $this->user->id))->toBeNull();
});

it('returns the community-exact name when only a community row matches', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'SHELL PIETER X',
        'generalized_pattern' => 'shell pieter',
        'name' => 'Shell — Pieter Nieuwlandstraat',
        'category' => null,
        'region' => 'NL',
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('SHELL PIETER X', $this->user->id))->toBe('Shell — Pieter Nieuwlandstraat');
});

it('returns the community-generalized name when only the generalized pattern matches', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'BCK*SHELL ROOT',
        'generalized_pattern' => 'shell pieter',
        'name' => 'Shell — Pieter (generalized)',
        'category' => null,
        'region' => 'NL',
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('BCK*SHELL PIETER NIEUW *0123', $this->user->id))->toBe('Shell — Pieter (generalized)');
});

it('lets a user-exact alias win over a matching community row', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'AH 1234 T9999',
        'generalized_pattern' => 'albert heijn',
        'name' => 'Community AH',
        'category' => null,
        'region' => 'NL',
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $db->connection()->table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'AH 1234 T9999',
        'generalized_pattern' => 'albert heijn',
        'friendly_name' => 'My Albert Heijn',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('AH 1234 T9999', $this->user->id))->toBe('My Albert Heijn');
});

it('matches a community regex: pattern with a word boundary, and does not over-match', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => 'regex:\bICA\b',
        // A regex row carries no generalized_pattern; it is matched only by
        // the regex scan, never the substring scan.
        'generalized_pattern' => '',
        'name' => 'ICA',
        'category' => 'Groceries',
        'region' => 'SE',
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('ICA MAXI STOCKHOLM', $this->user->id))->toBe('ICA');
    // MEDICAL contains "ICA"; the \b boundary is what keeps it from matching.
    expect($resolver->resolve('MEDICAL CENTRE AMSTERDAM', $this->user->id))->toBeNull();
});
