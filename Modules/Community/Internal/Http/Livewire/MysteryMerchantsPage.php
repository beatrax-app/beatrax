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

        // Null, not 0 and not 100: with no keyring every row blanks, and a
        // percentage over nothing is a number the page invented.
        $readableScanned = $scan['totalScanned'] - $scan['unreadableScanned'];
        $autoNamedPercent = $readableScanned > 0
            ? (int) round(($scan['resolvedScanned'] / $readableScanned) * 100)
            : null;

        $stats = [
            'mysteryCount' => count($grouped),
            'mappingsCount' => $corpus->mappingsCount(),
            'autoNamedPercent' => $autoNamedPercent,
            'contributorCount' => $corpus->contributionsCount($user->id),
        ];

        return $views->make('community::livewire.mystery-merchants-page', [
            'rows' => $cards,
            'stats' => $stats,
        ]);
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return array{grouped: array<string, array{description: string, count: int, lastSeen: ?string, paymentType: ?PaymentType}>, totalScanned: int, resolvedScanned: int, unreadableScanned: int}
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
        $unreadableScanned = 0;
        foreach ($rows as $row) {
            $totalScanned++;
            $stored = is_string($row->description) ? trim($row->description) : '';
            // transactions.description is a SensitiveFieldRegistry column and
            // the raw builder applies no cast. Undecrypted it never matches the
            // corpus, and the card's suggest button would offer the ciphertext
            // for publication to the shared list.
            $description = $stored === ''
                ? ''
                : trim($codec->decryptValue('transactions', 'description', $stored, $userId, $session)['value']);

            // A row that HAD a description and lost it to the decrypt is not
            // auto-named, it is unreadable — the difference between an honest
            // empty page and a fabricated 100%.
            if ($stored !== '' && $description === '') {
                $unreadableScanned++;

                continue;
            }

            if ($description === '' || $resolver->resolve($description, $userId) !== null) {
                $resolvedScanned++;

                continue;
            }
            $this->accumulate($grouped, $row, $description);
        }

        return [
            'grouped' => $grouped,
            'totalScanned' => $totalScanned,
            'resolvedScanned' => $resolvedScanned,
            'unreadableScanned' => $unreadableScanned,
        ];
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
