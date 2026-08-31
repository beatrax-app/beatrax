<?php

declare(strict_types=1);

namespace Modules\Community\Database\Seeders\Demo;

use Modules\Community\Models\CommunityMerchantMapping;
use Modules\Core\Models\User;

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
        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            foreach (self::MAPPINGS as $row) {
                // Both tiers. Every lookup and the headline count read the
                // shared one (user_id IS NULL); "Your contributions" counts the
                // reader's own, and a user-scoped row alone reached neither.
                foreach ([null, $primary->id] as $ownerId) {
                    CommunityMerchantMapping::query()->updateOrCreate(
                        [
                            'user_id' => $ownerId,
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
        }

        return CommunityMerchantMapping::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }
}
