<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Dto\AliasMatchPreviewResultDto;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class AliasMatchPreviewQuery
{
    // Shared with the save path: a pattern the preview refuses to test is a
    // pattern nobody has seen the effect of, and it used to be savable anyway.
    public const int MIN_PATTERN_LENGTH = 3;

    private const int SCAN_LIMIT = 500;

    private const int SAMPLE_LIMIT = 5;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private EncryptionMigrationService $encryptionService,
    ) {}

    public function preview(string $generalizedPattern, int $userId): AliasMatchPreviewResultDto
    {
        $trimmed = trim($generalizedPattern);
        if (mb_strlen($trimmed) < self::MIN_PATTERN_LENGTH) {
            return AliasMatchPreviewResultDto::withoutMatches(Lang::get('import::aliases.errors.too_short'));
        }

        $needle = mb_strtolower($trimmed);
        $encryptionEnabled = $this->encryptionService->isEnabled($userId);
        $matched = [];
        $total = 0;
        foreach ($this->recentTransactions($userId) as $row) {
            $plain = $this->decryptedRow($row, $userId, $encryptionEnabled);
            if ($plain === null || ! self::rowContains($plain, $needle)) {
                continue;
            }
            $total++;
            if (count($matched) < self::SAMPLE_LIMIT) {
                $matched[] = $plain;
            }
        }

        return AliasMatchPreviewResultDto::withMatches($total, $matched);
    }

    /**
     * @return iterable<stdClass>
     */
    private function recentTransactions(int $userId): iterable
    {
        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->select(['id', 'description', 'counterparty_name', 'posted_at', 'amount_minor'])
            // The column the preview prints, so the 500 rows it keeps are the
            // 500 the reader would call most recent. `id` breaks the tie a DATE
            // column leaves, or the cut is a different 500 run to run.
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT)
            ->get();

        return $rows;
    }

    // Null when a column came back BLANKED, which is the codec's signal for
    // ciphertext no epoch here opens. `decrypted: false` alone is not that
    // signal — it is also an ordinary plaintext row handed back untouched,
    // and dropping those reported "0 matches" for a pattern that does match.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function decryptedRow(stdClass $row, int $userId, bool $encryptionEnabled): ?stdClass
    {
        $description = $this->decryptField('description', $row->description ?? null, $userId);
        $counterparty = $this->decryptField('counterparty_name', $row->counterparty_name ?? null, $userId);

        if ($encryptionEnabled && (self::isUnreadable($description) || self::isUnreadable($counterparty))) {
            return null;
        }

        $plain = clone $row;
        $plain->description = $description['value'];
        $plain->counterparty_name = $counterparty['value'];

        return $plain;
    }

    /**
     * @return array{value: string, decrypted: bool}
     */
    private function decryptField(string $column, mixed $stored, int $userId): array
    {
        if (! is_string($stored) || $stored === '') {
            return ['value' => '', 'decrypted' => true];
        }

        return $this->codec->decryptValue('transactions', $column, $stored, $userId, ($this->session)());
    }

    /**
     * @param  array{value: string, decrypted: bool}  $field
     */
    private static function isUnreadable(array $field): bool
    {
        return ! $field['decrypted'] && $field['value'] === '';
    }

    // The same matcher MerchantNameResolver's generalized tier runs, over the
    // same column: resolve() is only ever handed transactions.description, so
    // falling back to counterparty_name here counted matches the alias itself
    // can never make.
    private static function rowContains(stdClass $row, string $needle): bool
    {
        $description = isset($row->description) && is_string($row->description) ? $row->description : '';

        return CorpusPatternMatcher::containsToken(mb_strtolower($description), $needle);
    }
}
