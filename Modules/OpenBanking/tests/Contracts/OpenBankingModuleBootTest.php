<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Providers\OpenBankingServiceProvider;

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
