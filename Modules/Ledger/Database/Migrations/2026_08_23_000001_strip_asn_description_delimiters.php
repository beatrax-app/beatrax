<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\Ledger\Internal\Services\StripAsnDescriptionDelimiters;

// The adapter fix only changes what future imports write. Every install that
// already imported an ASN CSV still holds the delimiter quotes in the ledger
// and in the search index, and this is the forward pass that removes them.

// It is not the only one. The schema moves at boot, before any lock screen is
// cleared, so on a sealed install this converts nothing and records nobody as
// done. SweepAsnDelimitersOnUnlock reaches those users. This stays because an
// install with no encryption is converted here and never has to wait.
/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md#why-a-migration-alone-could-not-deliver-it
 */
return new class extends Migration
{
    public function up(): void
    {
        /** @var StripAsnDescriptionDelimiters $service */
        $service = Container::getInstance()->make(StripAsnDescriptionDelimiters::class);

        $service->run();
    }

    public function down(): void
    {
        // Data-only: the quoted form is still in raw_payload, which this pass
        // never touches, and re-running up() is idempotent anyway.
    }
};
