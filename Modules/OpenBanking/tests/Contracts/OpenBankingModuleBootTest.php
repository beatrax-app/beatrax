<?php

declare(strict_types=1);

use Modules\OpenBanking\Providers\OpenBankingServiceProvider;
use Modules\OpenBanking\Public\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;

it('registers OpenBankingServiceProvider with the application', function (): void {
    expect(app()->getProviders(OpenBankingServiceProvider::class))->not->toBeEmpty();
});

it('autoloads the RemoteSourceAdapter contract with no statementMetadata method', function (): void {
    expect(interface_exists(RemoteSourceAdapter::class))->toBeTrue();

    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(RemoteSourceAdapter::class))->getMethods(),
    );

    expect($methods)->toContain('format')
        ->toContain('fetch')
        ->not->toContain('statementMetadata');
});

it('autoloads the FetchWindow DTO', function (): void {
    expect(class_exists(FetchWindow::class))->toBeTrue();
});

it('autoloads the OpenBankingCredentials DTO with the documented fields', function (): void {
    expect(class_exists(OpenBankingCredentials::class))->toBeTrue();

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(OpenBankingCredentials::class))->getProperties(),
    );

    expect($properties)->toEqual([
        'applicationId',
        'privateKeyPem',
        'sessionId',
        'consentExpiresAt',
        'bankScaHost',
        'institutionId',
    ]);
});

it('autoloads the OpenBankingConsentFailed event', function (): void {
    expect(class_exists(OpenBankingConsentFailed::class))->toBeTrue();
});
