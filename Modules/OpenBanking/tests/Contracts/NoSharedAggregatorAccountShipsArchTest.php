<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\PatternScan;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingWizardModal;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

// The connector's whole claim is that the maintainer is not in the data path,
// and the only thing holding that up is that no registration ships with the
// app. A single fallback constant beside a `?:` restores a shared account
// without touching the secrets store, the schema, or any existing assertion.

function noSharedAggregatorStripComments(string $contents): string
{
    return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
}

/**
 * @return list<string>
 */
function noSharedAggregatorSources(): array
{
    $root = base_path('Modules/OpenBanking');
    if (! is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $files[] = $path;
    }

    return $files;
}

function noSharedAggregatorRelative(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

/**
 * @return array{privateKeyPem: string, publicKeyPem: string, applicationId: string}
 */
function noSharedAggregatorMintOnce(): array
{
    $store = new class extends OpenBankingSecretsRepository
    {
        public ?OpenBankingCredentials $saved = null;

        public function __construct() {}

        public function saveApplication(int $userId, string $applicationId, string $privateKeyPem): void
        {
            $this->saved = new OpenBankingCredentials(
                applicationId: $applicationId,
                privateKeyPem: $privateKeyPem,
                sessionId: null,
                consentExpiresAt: null,
                bankScaHost: null,
                institutionId: null,
            );
        }

        public function load(int $userId, ?string $institutionId = null): ?OpenBankingCredentials
        {
            return $this->saved;
        }
    };

    // Names a reader without needing one: the mint has no database behind it,
    // and the wizard asks this collaborator for nothing but an id.
    $currentUser = new class implements CurrentUser
    {
        public function id(): int
        {
            return 1;
        }

        public function user(): User
        {
            throw new RuntimeException('The keypair mint never resolves a user row.');
        }

        public function periodStartDay(): int
        {
            return 1;
        }

        public function isAuthenticated(): bool
        {
            return true;
        }
    };

    $modal = new OpenBankingWizardModal;
    $modal->generateKeypair($store, $currentUser);

    $saved = $store->saved;
    expect($saved)->not->toBeNull();

    return [
        'privateKeyPem' => $saved->privateKeyPem,
        'publicKeyPem' => $modal->publicKeyPem,
        'applicationId' => $saved->applicationId,
    ];
}

const NO_SHARED_AGGREGATOR_PEM_PATTERN = '/-----BEGIN [A-Z ]*PRIVATE KEY-----/';

const NO_SHARED_AGGREGATOR_CREDENTIAL_PATTERN = '/private_key|application_id|applicationId|privateKeyPem/';

const NO_SHARED_AGGREGATOR_AMBIENT_PATTERN = '/(?<![>:])\b(?:env|config)\s*\(/';

it('ships no aggregator private key of its own', function (): void {
    $sources = noSharedAggregatorSources();

    // Counted first: a walk that resolved nothing reports a tree with no
    // shipped key, which is the answer a clean tree gives too. A floor of one
    // does not separate them either -- the module is ninety-odd files.
    expect(count($sources))->toBeGreaterThan(
        50,
        'The walk over Modules/OpenBanking reached '.count($sources).' files, which is too few to be the module.'
    );

    $carriers = [];
    foreach ($sources as $path) {
        $stripped = noSharedAggregatorStripComments((string) file_get_contents($path));
        if (PatternScan::matches(NO_SHARED_AGGREGATOR_PEM_PATTERN, $stripped)) {
            $carriers[] = noSharedAggregatorRelative($path);
        }
    }

    expect($carriers)->toBe(
        [],
        'The reader registers with the aggregator themselves and their key is generated on their '
        .'machine, so no shipped file may carry one — a bundled key is a maintainer-operated '
        ."account by another name. Offenders:\n  ".implode("\n  ", $carriers),
    );

    $violatingSample = 'private const string KEY = "-----BEGIN '.'PRIVATE KEY-----\\nMIIE...\\n-----END PRIVATE KEY-----";';
    expect(PatternScan::matches(NO_SHARED_AGGREGATOR_PEM_PATTERN, $violatingSample))->toBeTrue();

    $safeSample = '$pem = $credentials->privateKeyPem;';
    expect(PatternScan::matches(NO_SHARED_AGGREGATOR_PEM_PATTERN, $safeSample))->toBeFalse();
});

it('reads no aggregator credential out of the environment or a config file', function (): void {
    $sources = noSharedAggregatorSources();

    expect(count($sources))->toBeGreaterThan(
        50,
        'The walk over Modules/OpenBanking reached '.count($sources).' files, which is too few to be the module.'
    );

    $ambient = [];
    foreach ($sources as $path) {
        $stripped = noSharedAggregatorStripComments((string) file_get_contents($path));
        if (PatternScan::matches(NO_SHARED_AGGREGATOR_CREDENTIAL_PATTERN, $stripped)
            && PatternScan::matches(NO_SHARED_AGGREGATOR_AMBIENT_PATTERN, $stripped)) {
            $ambient[] = noSharedAggregatorRelative($path);
        }
    }

    expect($ambient)->toBe(
        [],
        'A credential read from env() or config() is one the packager can fill in for every '
        .'install at once, which is the shared account this connector exists without. Credentials '
        ."come from the reader's own secrets file. Offenders:\n  ".implode("\n  ", $ambient),
    );

    $violatingSample = "\$applicationId = env('OPEN_BANKING_APPLICATION_ID', 'shared-default');";
    expect(PatternScan::matches(NO_SHARED_AGGREGATOR_CREDENTIAL_PATTERN, $violatingSample))->toBeTrue();
    expect(PatternScan::matches(NO_SHARED_AGGREGATOR_AMBIENT_PATTERN, $violatingSample))->toBeTrue();
});

it('signs every aggregator request with the loaded credentials and nothing standing in for them', function (): void {
    $sites = [];
    $offenders = [];

    foreach (noSharedAggregatorSources() as $path) {
        $stripped = noSharedAggregatorStripComments((string) file_get_contents($path));

        foreach (PatternScan::all('/->sign\(\s*(.*?)\s*\)\s*;/s', $stripped)[1] ?? [] as $arguments) {
            $sites[] = noSharedAggregatorRelative($path);

            $normalized = PatternScan::replace('/\s+/', ' ', (string) $arguments);
            if ($normalized !== '$credentials->privateKeyPem, $credentials->applicationId') {
                $offenders[] = noSharedAggregatorRelative($path).': sign('.$normalized.')';
            }
        }
    }

    // The pin is worthless if the scan found no call to pin, and the signer is
    // reached from both the POST and the GET halves of the client.
    expect($sites)->toHaveCount(2);

    expect($offenders)->toBe(
        [],
        'The bearer token identifies an aggregator account, so both arguments must be the '
        ."reader's own loaded credentials verbatim — a `?:` onto a constant here is a shared "
        ."maintainer account every install would authenticate as. Offenders:\n  "
        .implode("\n  ", $offenders),
    );
});

it('mints a different key for every install rather than handing out one registration', function (): void {
    $first = noSharedAggregatorMintOnce();
    $second = noSharedAggregatorMintOnce();

    expect($first['privateKeyPem'])->toContain('PRIVATE KEY')
        ->and($second['privateKeyPem'])->not->toBe($first['privateKeyPem']);

    // The id stays empty until the reader pastes their own: the app has no
    // registration of its own to fall back on while they have not.
    expect($first['applicationId'])->toBe('')
        ->and($second['applicationId'])->toBe('');

    $resource = openssl_pkey_get_private($first['privateKeyPem']);
    expect($resource)->not->toBeFalse();

    $details = openssl_pkey_get_details($resource);
    expect($details)->not->toBeFalse()
        ->and($details['key'])->toBe($first['publicKeyPem']);
});
