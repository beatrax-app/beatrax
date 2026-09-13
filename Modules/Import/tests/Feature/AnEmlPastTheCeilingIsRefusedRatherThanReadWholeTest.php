<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\BoundedReadException;
use Modules\Core\Public\Support\UploadLimits;
use Modules\Import\Internal\Exceptions\ReceiptParseException;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Enums\SourceFormat;

// A wizard upload is the third door into RecordReceipt, beside the drop folder
// and the inbox handoff, and the only one of the three that read the whole file
// with no ceiling. The fixture is sparse — real RFC 822 headers so the shape
// reader still calls it a message, and a hole behind them — so the refusal is
// proved without putting 25 MB on disk.

beforeEach(function (): void {
    $this->fixtureUser = $this->seedFixtureUserAndAccount()['user'];

    $path = tempnam(sys_get_temp_dir(), 'eml-ceiling-');
    if ($path === false) {
        $this->fail('Could not allocate a temp file for the oversized message.');
    }

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        $this->fail('Could not open the oversized message for writing.');
    }

    fwrite($handle, "From: sender@example.com\r\nSubject: past the ceiling\r\n\r\nbody\r\n");
    ftruncate($handle, UploadLimits::MAX_MESSAGE_BYTES + 1);
    fclose($handle);

    $this->oversized = $path;
});

afterEach(function (): void {
    @unlink($this->oversized);
});

it('refuses a dropped-in message past the ceiling instead of reading it whole', function (): void {
    expect(filesize($this->oversized))->toBe(UploadLimits::MAX_MESSAGE_BYTES + 1);

    $accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    /** @var ParseStage $parse */
    $parse = $this->app->make(ParseStage::class);

    $refusal = null;
    try {
        iterator_to_array(
            $parse->run($this->oversized, SourceFormat::Eml->value, $accounts, $this->fixtureUser),
            false,
        );
    } catch (ReceiptParseException $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(ReceiptParseException::class)
        // The previous is what says this was the ceiling and not some other way
        // of failing to read a file: a message whose size is fine never reaches
        // BoundedRead's refusal at all.
        ->and($refusal?->getPrevious())->toBeInstanceOf(BoundedReadException::class)
        ->and((string) $refusal?->getPrevious()?->getMessage())
        ->toContain((string) UploadLimits::MAX_MESSAGE_BYTES);
});
