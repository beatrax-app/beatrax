<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking;

use Genkgo\Camt\Config;
use Genkgo\Camt\DTO\Message;
use Genkgo\Camt\Reader;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use SimpleXMLElement;
use Throwable;

// The two readings one statement file needs, behind one door, because both
// touch untrusted XML and the entity-loader denial that makes that safe belongs
// to neither of them alone.
final class Camt053XmlReader
{
    public function message(string $localPath): Message
    {
        return $this->withEntitiesDenied(function () use ($localPath): Message {
            // genkgo/camt's XSDs would reject unforeseen optional elements; the
            // sniffer plus the IBAN/amount validators enforce structure instead.
            $config = Config::getDefault();
            $config->disableXsdValidation();
            $reader = new Reader($config);

            try {
                return $reader->readFile($localPath);
            } catch (Throwable $e) {
                throw new InvalidAmountException(
                    sprintf('Failed to parse CAMT.053 XML: %s', $e->getMessage()),
                    0,
                    $e,
                );
            }
        });
    }

    // Each batch child's own TxDtls/CdtDbtInd, which no genkgo DTO carries: the
    // decoder hands every child the entry's indicator instead. Empty for a file
    // holding no batch, which pays nothing for a second tree it cannot use.
    /**
     * @link ../../../../../.docs/features/ingestion/a-batch-child-read-off-its-entry.md
     *
     * @return array<int, array<int, array<int, string>>> keyed by statement, entry and detail ordinal
     */
    public function childDirections(string $localPath, Message $message): array
    {
        if (! self::holdsABatch($message)) {
            return [];
        }

        $document = $this->parsed($localPath);

        return $document === null ? [] : self::directionsIn($document);
    }

    // From the string, never simplexml_load_file(): libxml puts the main
    // document through the external-entity resolver too, so under the denial
    // the file loads as false and a pass over it reads nothing at all.
    // genkgo's own Reader takes the same route for the same reason.
    private function parsed(string $localPath): ?SimpleXMLElement
    {
        $bytes = @file_get_contents($localPath);
        if ($bytes === false) {
            return null;
        }

        $document = $this->withEntitiesDenied(static fn (): SimpleXMLElement|false => simplexml_load_string(
            $bytes,
            SimpleXMLElement::class,
            LIBXML_NONET,
        ));

        return $document instanceof SimpleXMLElement ? $document : null;
    }

    /**
     * @return array<int, array<int, array<int, string>>>
     */
    private static function directionsIn(SimpleXMLElement $document): array
    {
        if (! isset($document->BkToCstmrStmt)) {
            return [];
        }

        $directions = [];
        $statementOrdinal = 0;
        foreach ($document->BkToCstmrStmt->Stmt as $xmlStatement) {
            $entryOrdinal = 0;
            foreach ($xmlStatement->Ntry as $xmlEntry) {
                $stated = self::statedDirections($xmlEntry);
                if ($stated !== []) {
                    $directions[$statementOrdinal][$entryOrdinal] = $stated;
                }
                $entryOrdinal++;
            }
            $statementOrdinal++;
        }

        return $directions;
    }

    private static function holdsABatch(Message $message): bool
    {
        foreach ($message->getRecords() as $record) {
            foreach ($record->getEntries() as $entry) {
                if (count($entry->getTransactionDetails()) > 1) {
                    return true;
                }
            }
        }

        return false;
    }

    // The FIRST <NtryDtls> and its children only, because that is the one the
    // decoder builds details from: reading a second one would offset every
    // detail ordinal after it against the DTOs this is read back beside. One
    // child is the entry written out, and its direction is the entry's.
    /**
     * @return array<int, string>
     */
    private static function statedDirections(SimpleXMLElement $xmlEntry): array
    {
        if (! isset($xmlEntry->NtryDtls->TxDtls) || count($xmlEntry->NtryDtls->TxDtls) < 2) {
            return [];
        }

        $stated = [];
        $detailOrdinal = 0;
        foreach ($xmlEntry->NtryDtls->TxDtls as $xmlDetail) {
            $indicator = (string) $xmlDetail->CdtDbtInd;
            if ($indicator === 'CRDT' || $indicator === 'DBIT') {
                $stated[$detailOrdinal] = $indicator;
            }
            $detailOrdinal++;
        }

        return $stated;
    }

    /**
     * @template TRead
     *
     * @param  callable(): TRead  $read
     * @return TRead
     */
    private function withEntitiesDenied(callable $read): mixed
    {
        // Denying every external entity closes XXE on untrusted statement XML;
        // XSD validation is off on every reader this wraps, so nothing
        // legitimate needs to resolve. The finally clause puts the loader back.
        libxml_set_external_entity_loader(
            static fn (?string $publicId, ?string $systemId, array $context): ?string => null
        );

        $previousErrorState = libxml_use_internal_errors(true);
        try {
            return $read();
        } finally {
            libxml_use_internal_errors($previousErrorState);
            libxml_set_external_entity_loader(null);
        }
    }
}
