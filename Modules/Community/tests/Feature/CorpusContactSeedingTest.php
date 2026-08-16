<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Ledger\Models\Category;

beforeEach(function (): void {
    $this->tmpRoot = sys_get_temp_dir().'/beatrax-corpus-contact-'.bin2hex(random_bytes(6));
    $this->merchantsDir = $this->tmpRoot.'/merchants';
    mkdir($this->merchantsDir, 0o755, true);

    $yaml = <<<'YAML'
entries:
  - pattern: "FULLCONTACT"
    name: "Full Contact BV"
    category: "Streaming"
    website: "https://example.com"
    cancel_url: "https://example.com/account/cancel"
    support_url: "https://example.com/help"
    support_phone: "0800 0402"
    support_email: "support@example.com"
  - pattern: "PARTIALCONTACT"
    name: "Partial Contact BV"
    cancel_url: "https://partial.example.com/cancel"
  - pattern: "NOCONTACT"
    name: "No Contact BV"
  - pattern: "BADCONTACT"
    name: "Bad Contact BV"
    cancel_url: "http://insecure.example.com/cancel"
    support_email: "not-an-address"
YAML;
    file_put_contents($this->merchantsDir.'/nl.yaml', $yaml);

    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.corpus.root', $this->tmpRoot);
});

afterEach(function (): void {
    if (isset($this->merchantsDir) && is_dir($this->merchantsDir)) {
        foreach (glob($this->merchantsDir.'/*.yaml') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->merchantsDir);
    }
    if (isset($this->tmpRoot) && is_dir($this->tmpRoot)) {
        @rmdir($this->tmpRoot);
    }
});

it('carries every contact field from the YAML through to the seeded row', function (): void {
    $user = makeCommunityTestUser('corpus-contact-a');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('community_merchant_mappings')->where('pattern', 'FULLCONTACT')->first();

    expect($row?->website)->toBe('https://example.com');
    expect($row?->cancel_url)->toBe('https://example.com/account/cancel');
    expect($row?->support_url)->toBe('https://example.com/help');
    expect($row?->support_phone)->toBe('0800 0402');
    expect($row?->support_email)->toBe('support@example.com');
});

it('leaves the contact columns null for an entry that declares none', function (): void {
    $user = makeCommunityTestUser('corpus-contact-b');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('community_merchant_mappings')->where('pattern', 'NOCONTACT')->first();

    expect($row?->name)->toBe('No Contact BV');
    expect($row?->website)->toBeNull();
    expect($row?->cancel_url)->toBeNull();
});

it('drops an unusable contact value while still seeding the entry', function (): void {
    $user = makeCommunityTestUser('corpus-contact-c');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('community_merchant_mappings')->where('pattern', 'BADCONTACT')->first();

    expect($row?->name)->toBe('Bad Contact BV');
    expect($row?->cancel_url)->toBeNull();
    expect($row?->support_email)->toBeNull();
});

it('clears a contact field the YAML no longer declares on re-seed', function (): void {
    $user = makeCommunityTestUser('corpus-contact-d');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    // A cancellation link that has gone stale must not survive the corpus
    // update that removed it — a dead cancel link is worse than none.
    file_put_contents($this->merchantsDir.'/nl.yaml', <<<'YAML'
entries:
  - pattern: "PARTIALCONTACT"
    name: "Partial Contact BV"
YAML);

    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('community_merchant_mappings')->where('pattern', 'PARTIALCONTACT')->first();

    expect($row?->cancel_url)->toBeNull();
});

it('reads a seeded merchant contact back by name', function (): void {
    $user = makeCommunityTestUser('corpus-contact-e');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    /** @var CommunityCorpusQuery $query */
    $query = $this->app->make(CommunityCorpusQuery::class);
    $contact = $query->contactForMerchant('Full Contact BV');

    expect($contact?->cancelUrl)->toBe('https://example.com/account/cancel');
    expect($contact?->supportPhone)->toBe('0800 0402');
});

it('returns no contact for a merchant that has none', function (): void {
    $user = makeCommunityTestUser('corpus-contact-f');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    /** @var CommunityCorpusQuery $query */
    $query = $this->app->make(CommunityCorpusQuery::class);

    expect($query->contactForMerchant('No Contact BV'))->toBeNull();
    expect($query->contactForMerchant('Never Heard Of It'))->toBeNull();
    expect($query->contactForMerchant('   '))->toBeNull();
});

it('references only categories the default tree defines across the whole bundled corpus', function (): void {
    // The loader downgrades an unknown category to a logged warning, so a typo
    // in a country file would ship as a corpus row nothing can categorise.
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.corpus.root', 'resources/corpus');

    $this->seed(DefaultCategoryTreeSeeder::class);
    $known = Category::query()->pluck('name')->all();

    /** @var CorpusLoader $loader */
    $loader = $this->app->make(CorpusLoader::class);

    $unknown = [];
    foreach ($loader->loadBundled() as $entry) {
        if ($entry->category !== null && ! in_array($entry->category, $known, true)) {
            $unknown[] = $entry->pattern.' → '.$entry->category;
        }
    }

    expect(array_values(array_unique($unknown)))
        ->toBe([], "Corpus categories must exist in the default tree. Unknown:\n  ".implode("\n  ", array_unique($unknown)));
});
