<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Strategy interface implemented by every per-source payment-type
 * hinter (ASN CSV, ASN CAMT.053, ASN MT940, ICS PDF, PayPal CSV) plus
 * the universal description-keyword fallback.
 *
 * The `PaymentTypeClassifierStage` collects every implementation bound
 * under the `import.payment_type_hinter` container tag and asks each
 * one to inspect a single `CanonicalTransaction`. Returning `null`
 * means "this hinter has no signal for the given row" — the classifier
 * skips it and consults the next tagged hinter. When every
 * source-specific hinter declines, the universal
 * description-keyword fallback runs; when even that returns null, the
 * classifier falls back to `PaymentType::Unknown`.
 *
 * Hinters are pure functions of their input: they read nothing from
 * the database, hold no per-call state, and are bound as singletons.
 * Side effects (persistence, logging) live downstream in the
 * classifier stage and the import pipeline.
 */
interface PaymentTypeHinter
{
    /**
     * Inspect a canonical row and return a `PaymentTypeHint` when this
     * hinter recognises the row's payment shape, or `null` otherwise.
     *
     * `$sourceFormat` is the canonical source-format identifier
     * (`asn-csv`, `asn-camt053`, `asn-mt940`, `ics-pdf`, `paypal-csv`,
     * receipt formats, etc.). Source-specific hinters use it to skip
     * rows that did not originate from their source; the universal
     * fallback ignores it and inspects every row.
     */
    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint;
}
