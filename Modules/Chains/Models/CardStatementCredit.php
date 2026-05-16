<?php

declare(strict_types=1);

namespace Modules\Chains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the card_statement_credits table — the carry-
 * forward credit ledger that captures overpayment surplus and
 * refund-after-close amounts flowing from one statement to the next.
 *
 * `from_statement_id` is the source statement (the one that went
 * overpaid or accepted a refund after close). `to_statement_id` is the
 * destination — nullable because surplus may exist before the next
 * statement period rolls in; a follow-up resolver pass updates the
 * pointer when the next statement lands.
 *
 * Allowed reasons: `overpayment`, `refund_after_close`. The DB-layer
 * trigger pair rejects anything else.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $from_statement_id
 * @property int|null $to_statement_id
 * @property int $amount_minor
 * @property string $reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CardStatementCredit extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'from_statement_id',
        'to_statement_id',
        'amount_minor',
        'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CardStatement, $this> */
    public function fromStatement(): BelongsTo
    {
        return $this->belongsTo(CardStatement::class, 'from_statement_id');
    }

    /** @return BelongsTo<CardStatement, $this> */
    public function toStatement(): BelongsTo
    {
        return $this->belongsTo(CardStatement::class, 'to_statement_id');
    }
}
