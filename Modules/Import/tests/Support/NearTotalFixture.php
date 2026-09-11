<?php

declare(strict_types=1);

namespace Modules\Import\Tests\Support;

use Modules\Core\Models\User;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Import\Public\Services\EloquentAccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;
use RuntimeException;

// The pair Receipts' FingerprintParityTest pins — one PayPal activity export
// row and the .eml PayPal mailed for the same payment — read through the same
// adapters the wizard reads them through. The near-total fixtures beside the
// receipt are that mail with a different printed total and nothing else.
// A class rather than Pest helper functions: a function declared in one test
// file is global and only defined when that file is in the run.
final class NearTotalFixture
{
    public static function path(string $name): string
    {
        return __DIR__.'/../../../Receipts/tests/fixtures/paypal/'.$name;
    }

    public static function statementCanonical(User $user, int $accountId): CanonicalTransaction
    {
        $adapter = app(SourceAdapterRegistry::class)->for(SourceFormat::PaypalCsv->value);

        foreach ($adapter->parse(self::path('paired-csv-row.csv'), new EloquentAccountResolver($user)) as $row) {
            return self::normalize($row, $user, $accountId, SourceFormat::PaypalCsv->value);
        }

        throw new RuntimeException('The paired PayPal CSV fixture yielded no row.');
    }

    public static function receiptCanonical(User $user, int $accountId, string $fixture): CanonicalTransaction
    {
        $outcome = app(RecordReceipt::class)((string) file_get_contents(self::path($fixture)), $user, $fixture);
        $parsed = $outcome->parsed ?? throw new RuntimeException("No matcher claimed {$fixture}.");

        return self::normalize(
            (new ReceiptSourceAdapter)->toSourceDto($parsed, sourceRowIndex: 0),
            $user,
            $accountId,
            SourceFormat::Eml->value,
        );
    }

    private static function normalize(SourceTransactionDto $source, User $user, int $accountId, string $format): CanonicalTransaction
    {
        /** @var ImportRun $run */
        $run = ImportRun::create([
            'user_id' => $user->id,
            'source_format' => $format,
            'raw_file_path' => '/tmp/near-total-'.bin2hex(random_bytes(4)).'.dat',
            'sha256' => hash('sha256', 'near-total-'.uniqid('', true)),
            'uploaded_at' => '2026-05-17 12:00:00',
            'status' => 'confirmed',
        ]);

        return app(NormalizeStage::class)->run($source, $accountId, $user, $run->id, $format);
    }
}
