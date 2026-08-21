<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

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
        SensitiveColumnCodec $codec,
        Session $session,
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

        $scan = $this->scanMysteryRows($rows, $resolver, $codec, $session, $user->id);
        $grouped = $scan['grouped'];

        uasort($grouped, static function (array $a, array $b): int {
            $countOrder = $b['count'] <=> $a['count'];

            return $countOrder !== 0 ? $countOrder : strcmp($a['description'], $b['description']);
        });

        $cards = array_slice(array_values($grouped), 0, self::CARD_LIMIT);

        $autoNamedPercent = $scan['totalScanned'] > 0
            ? (int) round(($scan['resolvedScanned'] / $scan['totalScanned']) * 100)
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

    /**
     * @param  iterable<stdClass>  $rows
     * @return array{grouped: array<string, array{description: string, count: int, lastSeen: ?string, paymentType: ?PaymentType}>, totalScanned: int, resolvedScanned: int}
     */
    private function scanMysteryRows(
        iterable $rows,
        MerchantNameResolver $resolver,
        SensitiveColumnCodec $codec,
        Session $session,
        int $userId,
    ): array {
        $grouped = [];
        $totalScanned = 0;
        $resolvedScanned = 0;
        foreach ($rows as $row) {
            $totalScanned++;
            $description = is_string($row->description) ? trim($row->description) : '';
            // transactions.description is a SensitiveFieldRegistry column and
            // the raw builder applies no cast. Undecrypted it never matches the
            // corpus, and the card's suggest button would offer the ciphertext
            // for publication to the shared list.
            if ($description !== '') {
                $description = trim($codec->decryptValue('transactions', 'description', $description, $userId, $session)['value']);
            }
            if ($description === '' || $resolver->resolve($description, $userId) !== null) {
                $resolvedScanned++;

                continue;
            }
            $this->accumulate($grouped, $row, $description);
        }

        return ['grouped' => $grouped, 'totalScanned' => $totalScanned, 'resolvedScanned' => $resolvedScanned];
    }

    /**
     * @param  array<string, array{description: string, count: int, lastSeen: ?string, paymentType: ?PaymentType}>  $grouped
     */
    private function accumulate(array &$grouped, stdClass $row, string $description): void
    {
        if (! isset($grouped[$description])) {
            $grouped[$description] = [
                'description' => $description,
                'count' => 0,
                'lastSeen' => null,
                'paymentType' => self::asPaymentType($row->payment_type ?? null),
            ];
        }
        $grouped[$description]['count']++;
        $postedAt = is_string($row->posted_at) ? $row->posted_at : null;
        if ($postedAt !== null && ($grouped[$description]['lastSeen'] === null || $grouped[$description]['lastSeen'] < $postedAt)) {
            $grouped[$description]['lastSeen'] = $postedAt;
        }
    }

    private static function asPaymentType(mixed $raw): ?PaymentType
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return PaymentType::tryFrom($raw);
    }
}
