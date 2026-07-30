<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\OpenBanking\Public\Dto\OpenBankingConnectionView;

/*
 * The one question the settings page asks this view that is not a plain field
 * read. It gates the failure notice, so a null status — no attempt has run
 * yet — must not render as a failure the user cannot explain.
 */

function obcvView(?string $lastAttemptStatus): OpenBankingConnectionView
{
    return new OpenBankingConnectionView(
        connectionId: 1,
        enabled: true,
        institutionId: 'ASNBNL21',
        bankDisplayName: 'ASN Bank',
        consentStatus: 'connected',
        consentExpiresAt: CarbonImmutable::parse('2026-10-19 00:00:00'),
        lastSuccessfulSyncAt: null,
        lastAttemptAt: null,
        lastAttemptStatus: $lastAttemptStatus,
        aggregator: 'Enable Banking',
        whatsFetched: 'transactions',
    );
}

it('reports a failed last attempt for every status that is not ok', function (?string $status, bool $failed): void {
    expect(obcvView($status)->lastAttemptFailed())->toBe($failed);
})->with([
    'never attempted' => [null, false],
    'succeeded' => ['ok', false],
    'errored' => ['error', true],
    'consent failed' => ['consent_failed', true],
]);
