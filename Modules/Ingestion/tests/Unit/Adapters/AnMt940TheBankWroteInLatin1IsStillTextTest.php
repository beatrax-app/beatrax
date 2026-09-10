<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Written from the shipped fixture rather than committed as bytes, so the one
// thing that makes this file different is visible in the diff.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();

    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $utf8 = (string) file_get_contents(base_path('tests/fixtures/asn-mt940-sample-1.sta'));
    $this->legacyPath = tempnam(sys_get_temp_dir(), 'mt940-latin1-').'.sta';
    file_put_contents(
        $this->legacyPath,
        str_replace('?32Albert Heijn 4', "?32Caf\xE9 Bakker", $utf8),
    );
});

afterEach(function (): void {
    if (is_string($this->legacyPath) && is_file($this->legacyPath)) {
        @unlink($this->legacyPath);
    }
});

it('reads a latin-1 counterparty name as text rather than as bytes', function (): void {
    $names = [];
    foreach ($this->app->make(Mt940Adapter::class)->parse($this->legacyPath, $this->resolver) as $dto) {
        $names[] = $dto->counterpartyName;
    }

    $unreadable = array_values(array_filter(
        $names,
        static fn (?string $name): bool => $name !== null && ! mb_check_encoding($name, 'UTF-8'),
    ));

    expect($names)->not->toBeEmpty()
        ->and($unreadable)->toBe([]);
});

it('keeps the source row of a latin-1 statement storable as JSON', function (): void {
    $payloads = [];
    foreach ($this->app->make(Mt940Adapter::class)->parse($this->legacyPath, $this->resolver) as $dto) {
        $payloads[] = json_encode($dto->rawPayload);
    }

    expect($payloads)->not->toBeEmpty()
        ->and(array_filter($payloads, static fn (mixed $json): bool => $json === false))->toBe([]);
});

it('refuses a source row it cannot store rather than writing a false into the column', function (): void {
    $canonical = new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: now()->toImmutable(),
        bookedAt: now()->toImmutable(),
        valueDate: now()->toImmutable(),
        amountMinor: -100,
        currency: 'EUR',
        settledAmountMinor: -100,
        settledCurrency: 'EUR',
        counterpartyName: 'Bakker',
        counterpartyIban: null,
        counterpartyNormalized: 'bakker',
        normalizationVersion: 3,
        description: null,
        categoryId: null,
        sourceFormat: 'mt940',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: null,
        rawPayload: ['narrative' => "Caf\xE9"],
    );

    expect(fn (): array => $canonical->toAttributes())->toThrow(JsonException::class);
});
