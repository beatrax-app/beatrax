<?php

declare(strict_types=1);

namespace Modules\Counterparties\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the counterparties table — one row per
 * (user_id, slug) materialises one entity the user transacts with.
 *
 * Allowed `type` values: `merchant`, `personal`, `bank`, `government`,
 * `self_account`, `unknown`. The allowed set is enforced by paired
 * BEFORE INSERT / BEFORE UPDATE OF type triggers on the counterparties
 * table; a typo in the application layer fails loud at the DB
 * boundary rather than landing a silently-broken row. Note: the
 * `self_account` type is part of the trigger set but the resolver
 * intentionally never writes a row of that type — the self-account
 * branch short-circuits resolution before any upsert.
 *
 * The `metadata` JSON cast carries per-type opaque payload (e.g. the
 * `subcategory => 'fee'` flag set on bank-fee rows; the bridge
 * provenance for known-counterparty-IBAN rows) so type-aware profile
 * pages can render without a second lookup.
 *
 * Privacy posture for the `personal` type: `slug` is derived from
 * `display_name` only (kebab-cased with collision suffixing); the
 * `iban` column IS populated when the source transaction carried one,
 * but it never leaks into the slug column — the slug is the URL
 * routing key and a personal IBAN never appears in the URL. The
 * resolver enforces this on the write side; the profile page enforces
 * it on the rendering side via an explicit "Show IBAN" toggle.
 *
 * Cross-user posture: every production read / write path that
 * operates on this table carries an EXPLICIT `where('user_id', ...)`
 * filter through the raw query builder. The `BelongsToUser` global
 * scope is a SECONDARY guard that fires only when an Eloquent query
 * reaches the model inside an HTTP-bound request; it does not fire
 * under queue workers, console commands, or model factory paths.
 * Defence in depth: the explicit filter is the primary guard, and a
 * new query path MUST carry its own `where('user_id', ...)`
 * regardless of what the trait does. The trait stays so that any
 * future Eloquent surface gets the scope wired automatically; the
 * explicit-filter rule still applies on top.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property string $slug
 * @property string $display_name
 * @property string|null $iban
 * @property string|null $merchant_name
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Counterparty extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'counterparties';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'slug',
        'display_name',
        'iban',
        'merchant_name',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
