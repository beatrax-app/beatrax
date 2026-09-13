<?php

declare(strict_types=1);

namespace Modules\Ingestion\Tests\Support;

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\CsvPreset;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use RuntimeException;

// A file body written to disk under a .csv name and read through a shipped
// preset. Hostile bodies are one-offs rather than fixtures: each says its
// whole story in the test that writes it.
final class CsvHandedToTheApp
{
    public const string N26_HEADER = '"Booking Date","Value Date","Partner Name","Partner Iban","Type","Payment Reference","Account Name","Amount (EUR)","Original Amount","Original Currency","Exchange Rate"';

    public const string ING_HEADER = '"Datum","Naam/Omschrijving","Rekening","Tegenrekening","Code","Af Bij","Bedrag (EUR)","MutatieSoort","Mededelingen"';

    public const string REVOLUT_HEADER = 'Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance';

    /**
     * @return list<SourceTransactionDto>
     */
    public static function parsedThrough(string $format, string $body): array
    {
        $preset = (new CsvPresetRegistry)->get($format);
        if ($preset === null) {
            throw new RuntimeException(sprintf('No CSV preset named %s.', $format));
        }

        return self::parsedThroughPreset($preset, $body);
    }

    // For a preset the registry does not ship. HeaderSniffer is final and
    // resolves its own preset from the format id, so the body still has to
    // satisfy the registered preset of whichever id this one names.
    /**
     * @return list<SourceTransactionDto>
     */
    public static function parsedThroughPreset(CsvPreset $preset, string $body): array
    {
        $path = sys_get_temp_dir().'/beatrax-csv-'.bin2hex(random_bytes(8)).'.csv';
        file_put_contents($path, $body);

        $adapter = new GenericCsvAdapter($preset, new GenericCsvAmountParser, new HeaderSniffer);

        try {
            /** @var list<SourceTransactionDto> $rows */
            $rows = iterator_to_array($adapter->parse($path, self::resolver()), preserve_keys: false);

            return $rows;
        } finally {
            @unlink($path);
        }
    }

    private static function resolver(): AccountResolver
    {
        return new class implements AccountResolver
        {
            public function resolve(string $iban): AccountResolution
            {
                return AccountResolution::unknown($iban);
            }
        };
    }
}
