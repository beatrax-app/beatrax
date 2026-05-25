<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Post-parse classifier that resolves the `PaymentType` for every
 * canonical row by consulting every container-tagged
 * `PaymentTypeHinter` and choosing the highest-confidence verdict.
 *
 * Walks `$this->hinters` in registration order, asking each for a
 * `PaymentTypeHint`. Source-specific hinters return `null` for rows
 * that did not originate from their source; the universal
 * description-keyword fallback inspects every row and always emits a
 * low-confidence verdict when any keyword matches.
 *
 * The winner is the hint with the highest `confidence` value; ties
 * resolve by hinter registration order (the earlier-registered
 * hinter wins). When every hinter declines, the stage falls back to
 * `PaymentType::Unknown` so the canonical row's `paymentType` is
 * never null after this stage runs.
 *
 * `run()` returns a NEW `CanonicalTransaction` via the immutable
 * `withPaymentType()` wither — the stage never mutates its inputs.
 *
 * Pure / stateless: the stage holds no per-call state and is bound
 * as a singleton. The classifier emits no per-row log line — the
 * resolved verdict lives on the returned `CanonicalTransaction`'s
 * `paymentType` property and survives into the PreviewRowDto, so
 * downstream observers (the preview UI, tests, the audit log
 * surface) read it from the data path rather than from log scrape.
 * A 500-row import therefore produces zero classifier log lines,
 * keeping `/dev/logs` tailable for the developer.
 */
final class PaymentTypeClassifierStage
{
    /**
     * @param  iterable<PaymentTypeHinter>  $hinters  Hinters bound under the `import.payment_type_hinter` container tag, in registration order.
     */
    public function __construct(
        private readonly iterable $hinters,
    ) {}

    public function run(CanonicalTransaction $tx, User $user, string $sourceFormat): CanonicalTransaction
    {
        unset($user);

        $winner = null;

        foreach ($this->hinters as $hinter) {
            $hint = $hinter->hint($tx, $sourceFormat);
            if ($hint === null) {
                continue;
            }
            if ($winner === null || $hint->confidence > $winner->confidence) {
                $winner = $hint;
            }
        }

        if ($winner === null) {
            return $tx->withPaymentType(PaymentType::Unknown);
        }

        return $tx->withPaymentType($winner->type);
    }
}
