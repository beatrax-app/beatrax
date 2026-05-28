<?php

declare(strict_types=1);

namespace Modules\Import\Database\Seeders\Demo;

use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;

/**
 * Materialises three additional `merchant_aliases` rows for the
 * primary demo user so the alias-resolution path exercises a few
 * extra raw-statement-description rewrites beyond the 24-row baseline
 * that DemoCounterpartiesSeeder seeds as a side-effect of its
 * resolver invocation.
 *
 * Each row models a recognisable raw description pattern a user
 * would normally curate by hand through `Settings → Aliases`:
 *
 *   - "PYPL *EZPORT BV" → "EZ-Port BV (PayPal)"
 *   - "ALBERT HEIJN 1234" → "Albert Heijn"
 *   - "GOOGLE *YOUTUBE PREMIUM" → "YouTube Premium"
 *
 * Idempotency: `updateOrCreate` keyed on `(user_id, pattern)` matches
 * the existing UNIQUE on `merchant_aliases` so a re-run reuses the
 * existing row.
 */
final class DemoMerchantAliasesSeeder
{
    /**
     * @var list<array{pattern: string, generalizedPattern: string, friendlyName: string}>
     */
    private const ALIASES = [
        [
            'pattern' => 'PYPL *EZPORT BV',
            'generalizedPattern' => 'pypl ezport',
            'friendlyName' => 'EZ-Port BV (PayPal)',
        ],
        [
            'pattern' => 'ALBERT HEIJN 1234',
            'generalizedPattern' => 'albert heijn',
            'friendlyName' => 'Albert Heijn',
        ],
        [
            'pattern' => 'GOOGLE *YOUTUBE PREMIUM',
            'generalizedPattern' => 'google youtube premium',
            'friendlyName' => 'YouTube Premium',
        ],
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::ALIASES as $row) {
                MerchantAlias::query()->updateOrCreate(
                    [
                        'user_id' => $primary->id,
                        'pattern' => $row['pattern'],
                    ],
                    [
                        'generalized_pattern' => $row['generalizedPattern'],
                        'friendly_name' => $row['friendlyName'],
                        'merged_from' => null,
                    ],
                );
            }
        }

        return MerchantAlias::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }
}
