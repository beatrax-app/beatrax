<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#5-payment-type-classification-paymenttypeclassifierstage
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
