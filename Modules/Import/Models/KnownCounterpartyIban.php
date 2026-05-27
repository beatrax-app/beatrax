<?php

declare(strict_types=1);

namespace Modules\Import\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the known_counterparty_ibans table — per-user
 * alias from a real institution IBAN (the one that appears on the
 * ASN side of a cross-account hop) to the user's own account kind
 * (`bank`, `ics_card`, `paypal`) that uses a synthetic IBAN literal.
 *
 * Cross-user posture: every production read / write path that
 * operates on this table carries an EXPLICIT
 * `where('user_id', $userId)` filter — the resolver and the seeder
 * both do so. The `BelongsToUser` global scope is a SECONDARY guard
 * that fires only when an Eloquent query reaches the model within an
 * HTTP-bound request; it does not fire under queue workers, console
 * commands (e.g. `beatrax:install`), or model factory paths. Defence
 * in depth: the explicit filter is the primary guard, and a new
 * query path MUST carry its own `where('user_id', ...)` regardless
 * of what the trait does.
 *
 * IBAN shape: the `real_iban` column is `string(34)` and currently
 * carries no shape validation — the only writer is
 * DefaultKnownCounterpartyIbansSeeder whose values are literal
 * compile-time constants. When a user-facing admin surface ever
 * exposes alias creation (settings page, /counterparties triage
 * action), add a `Modules\Import\Public\Validation\IbanRule` (the
 * project's transitive `jschaedl/iban-validation` dependency is
 * already on the classpath via `genkgo/camt`) and validate at the
 * action boundary. Out of v1 scope.
 *
 * @property int $id
 * @property int $user_id
 * @property string $real_iban
 * @property string $target_account_kind
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class KnownCounterpartyIban extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'known_counterparty_ibans';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'real_iban',
        'target_account_kind',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
