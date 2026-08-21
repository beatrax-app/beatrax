<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Core\Public\Enums\Country;
use Modules\Import\Public\Services\MerchantNameResolver;

// desktop-04, region half. `Betaalautomaat Albert Heijn 1042 Amsterdam` resolved
// to the Czech chain ALBERT: CorpusLoader sorts its files, so cz.yaml seeds
// before nl.yaml, takes the lower id, and wins the first-match scan. The word
// boundary cannot help — `albert` really is a whole token in that description.

function regionMapping(DatabaseManager $db, string $pattern, string $generalized, string $name, ?string $region): void
{
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => $pattern,
        'generalized_pattern' => $generalized,
        'name' => $name,
        'category' => null,
        'region' => $region,
        'contributor' => 'beatrax-bot',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

function regionUser(DatabaseManager $db, string $username, ?Country $country): User
{
    $user = User::create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $db->connection()->table('users')
        ->where('id', $user->id)
        ->update(['country_code' => $country?->value ?? '']);

    return $user;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    // Seeded in the order CorpusLoader would: cz.yaml before nl.yaml, so the
    // Czech row holds the lower id and wins any unscoped first-match scan.
    regionMapping($db, 'ALBERT', 'albert', 'Albert', 'CZ');
    regionMapping($db, 'ALBERT HEIJN', 'albert heijn', 'Albert Heijn', 'NL');
});

it('does not resolve a Dutch statement line against the Czech corpus', function (): void {
    $user = regionUser($this->db, 'region-nl-reader', Country::Nl);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('Betaalautomaat Albert Heijn 1042 Amsterdam', (int) $user->id))
        ->toBe('Albert Heijn');
});

it('scopes the exact tier to the reader region too', function (): void {
    $user = regionUser($this->db, 'region-nl-exact', Country::Nl);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('ALBERT', (int) $user->id))->toBeNull();
});

it('scopes the regex tier to the reader region too', function (): void {
    regionMapping($this->db, CorpusPatternMatcher::REGEX_PREFIX.'\bKAUFLAND\b', '', 'Kaufland', 'CZ');

    $user = regionUser($this->db, 'region-nl-regex', Country::Nl);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('KAUFLAND PRAHA 4', (int) $user->id))->toBeNull();
});

// The decision already made for the government and bank-fee rules: no country
// set widens to every region rather than classifying nothing. But that fallback
// is what a FRESH INSTALL does — anyone who skips the country selector at signup
// lands here — and it is exactly where the device reproduction bit. So the
// fallback has to stand on its own: with every region loaded, the MOST SPECIFIC
// pattern wins, not whichever file sorted first.
it('still widens to every region for a reader who has named no country', function (): void {
    regionMapping($this->db, 'KAUFLAND', 'kaufland', 'Kaufland', 'CZ');

    $user = regionUser($this->db, 'region-no-country', null);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('KAUFLAND PRAHA 4', (int) $user->id))->toBe('Kaufland');
});

it('prefers the more specific pattern over the shorter one when no country narrows the corpus', function (): void {
    $user = regionUser($this->db, 'region-no-country-specific', null);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('Betaalautomaat Albert Heijn 1042 Amsterdam', (int) $user->id))
        ->toBe('Albert Heijn');
});

// Verbatim from the device: a clean install, no country chosen, the ASN demo
// statement's own `Albert Heijn 1042` counterparty field, rendered on the import
// preview — the first thing a new reader ever sees of their own data.
it('does not show a fresh no-country install the Czech chain for its Dutch supermarket', function (): void {
    $user = regionUser($this->db, 'region-fresh-install', null);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('Albert Heijn 1042', (int) $user->id))->not->toBe('Albert');
    expect($resolver->resolve('Albert Heijn 1042', (int) $user->id))->toBe('Albert Heijn');
});

it('prefers the more specific pattern inside one region too', function (): void {
    regionMapping($this->db, 'JUMBO', 'jumbo', 'Jumbo', 'NL');
    regionMapping($this->db, 'JUMBO FOODMARKT', 'jumbo foodmarkt', 'Jumbo Foodmarkt', 'NL');

    $user = regionUser($this->db, 'region-nl-specific', Country::Nl);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('JUMBO FOODMARKT BREDA', (int) $user->id))->toBe('Jumbo Foodmarkt');
});

// The alias tier runs before the corpus, so the same rule has to hold there or a
// reader's own short alias beats their own longer one by insertion order alone.
it('prefers the more specific of the readers own aliases', function (): void {
    $user = regionUser($this->db, 'region-alias-specific', Country::Nl);

    foreach ([['ALBERT', 'albert', 'Albert (short)'], ['ALBERT HEIJN', 'albert heijn', 'Albert Heijn (mine)']] as [$pattern, $generalized, $friendly]) {
        $this->db->connection()->table('merchant_aliases')->insert([
            'user_id' => $user->id,
            'pattern' => $pattern,
            'generalized_pattern' => $generalized,
            'friendly_name' => $friendly,
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ]);
    }

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('Betaalautomaat Albert Heijn 1042 Amsterdam', (int) $user->id))
        ->toBe('Albert Heijn (mine)');
});

it('still matches a mapping that claims no region of its own', function (): void {
    regionMapping($this->db, 'NETFLIX.COM', 'netflix', 'Netflix', null);

    $user = regionUser($this->db, 'region-nl-global', Country::Nl);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('NETFLIX.COM AMSTERDAM', (int) $user->id))->toBe('Netflix');
});

// The reader's own aliases are theirs, wherever they live: region scoping is a
// property of the shared corpus, not of a name the reader typed themselves.
it('never scopes the reader own aliases by region', function (): void {
    $user = regionUser($this->db, 'region-own-alias', Country::Nl);

    $this->db->connection()->table('merchant_aliases')->insert([
        'user_id' => $user->id,
        'pattern' => 'ALBERT',
        'generalized_pattern' => 'albert',
        'friendly_name' => 'My Czech corner shop',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('ALBERT', (int) $user->id))->toBe('My Czech corner shop');
});
