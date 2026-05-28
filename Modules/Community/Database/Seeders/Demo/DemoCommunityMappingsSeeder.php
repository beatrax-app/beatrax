<?php

declare(strict_types=1);

namespace Modules\Community\Database\Seeders\Demo;

use Modules\Community\Models\CommunityMerchantMapping;
use Modules\Core\Models\User;

/**
 * Materialises three `community_merchant_mappings` rows for the
 * primary demo user so the crowd-merchant identification path on the
 * import preview and the `/community/mystery-merchants` page both
 * have data to render against.
 *
 * Each row is a per-user override (`user_id = $primary->id`) carrying
 * a recognisable Dutch retail pattern that does NOT collide with the
 * bundled global corpus rows seeded at install time — the
 * `(user_id, pattern)` UNIQUE allows the same pattern to coexist
 * between a global row and a per-user row, and the demo seeder leans
 * into that to exercise the per-user override branch of the
 * resolver.
 *
 * Idempotency: `updateOrCreate` keyed on `(user_id, pattern)` matches
 * the UNIQUE on the community_merchant_mappings table so a second
 * seed run reuses the existing row.
 */
final class DemoCommunityMappingsSeeder
{
    /**
     * @var list<array{pattern: string, generalizedPattern: string, name: string, category: ?string, region: ?string}>
     */
    private const MAPPINGS = [
        [
            'pattern' => 'STG TUINBOUW NL',
            'generalizedPattern' => 'stg tuinbouw',
            'name' => 'Stichting Tuinbouw NL',
            'category' => 'memberships',
            'region' => 'NL',
        ],
        [
            'pattern' => 'PYPL *EZPORT BV',
            'generalizedPattern' => 'pypl ezport',
            'name' => 'EZ-Port BV (PayPal)',
            'category' => 'travel',
            'region' => 'NL',
        ],
        [
            'pattern' => 'ICS PURCHASE 1234',
            'generalizedPattern' => 'ics purchase',
            'name' => 'ICS Generic Purchase',
            'category' => null,
            'region' => 'NL',
        ],
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::MAPPINGS as $row) {
                CommunityMerchantMapping::query()->updateOrCreate(
                    [
                        'user_id' => $primary->id,
                        'pattern' => $row['pattern'],
                    ],
                    [
                        'generalized_pattern' => $row['generalizedPattern'],
                        'name' => $row['name'],
                        'category' => $row['category'],
                        'region' => $row['region'],
                        'contributor' => $primary->username,
                    ],
                );
            }
        }

        return CommunityMerchantMapping::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }
}
