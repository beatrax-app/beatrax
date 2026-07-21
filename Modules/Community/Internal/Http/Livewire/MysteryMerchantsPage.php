<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Import\Public\Services\MerchantNameResolver;
use stdClass;

/**
 * @link ../../../../../.docs/features/community/architecture.md
 */
final class MysteryMerchantsPage extends Component
{
    private const CARD_LIMIT = 24;

    private const SCAN_LIMIT = 2000;

    public function render(
        ViewFactory $views,
        DatabaseManager $db,
        CurrentUser $currentUser,
        CommunityCorpusQuery $corpus,
        MerchantNameResolver $resolver,
    ): View {
        $user = $currentUser->user();

        /** @var iterable<stdClass> $rows */
        $rows = $db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT)
            ->get([
                'id',
                'description',
                'posted_at',
                'payment_type',
            ]);

        /** @var array<string, array{description: string, count: int, lastSeen: ?string, paymentType: ?PaymentType}> $grouped */
        $grouped = [];
        $totalScanned = 0;
        $resolvedScanned = 0;
        foreach ($rows as $row) {
            $totalScanned++;
            $description = is_string($row->description) ? trim($row->description) : '';
            if ($description === '') {
                $resolvedScanned++;

                continue;
            }
            if ($resolver->resolve($description, $user->id) !== null) {
                $resolvedScanned++;

                continue;
            }

            $key = $description;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'description' => $description,
                    'count' => 0,
                    'lastSeen' => null,
                    'paymentType' => self::asPaymentType($row->payment_type ?? null),
                ];
            }
            $grouped[$key]['count']++;
            $postedAt = is_string($row->posted_at) ? $row->posted_at : null;
            if ($postedAt !== null && ($grouped[$key]['lastSeen'] === null || $grouped[$key]['lastSeen'] < $postedAt)) {
                $grouped[$key]['lastSeen'] = $postedAt;
            }
        }

        uasort($grouped, static function (array $a, array $b): int {
            $countOrder = $b['count'] <=> $a['count'];

            return $countOrder !== 0 ? $countOrder : strcmp($a['description'], $b['description']);
        });

        $cards = array_slice(array_values($grouped), 0, self::CARD_LIMIT);

        $autoNamedPercent = $totalScanned > 0
            ? (int) round(($resolvedScanned / $totalScanned) * 100)
            : 0;

        $stats = [
            'mysteryCount' => count($grouped),
            'mappingsCount' => $corpus->mappingsCount(),
            'autoNamedPercent' => $autoNamedPercent,
            'contributorCount' => 0,
        ];

        return $views->make('community::livewire.mystery-merchants-page', [
            'rows' => $cards,
            'stats' => $stats,
        ]);
    }

    private static function asPaymentType(mixed $raw): ?PaymentType
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return PaymentType::tryFrom($raw);
    }
}
