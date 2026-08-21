<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

final class RecoveryCodeMinter
{
    private const int SHEET_SIZE = 10;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly Clock $clock,
        private readonly RecoveryCodeGenerator $generator,
    ) {}

    /**
     * @return list<string> the fresh plaintext codes, in the order they were issued
     */
    public function issueFor(int $userId): array
    {
        $codesPlain = $this->mint();
        $issuedAt = $this->clock->now();

        // Written through the query builder, not the model: `created_at` is not
        // fillable, so an Eloquent create() dropped the stamp and the row took
        // the database's own CURRENT_TIMESTAMP in place of the injected clock.
        $this->db->connection()->table('user_recovery_codes')->insert(array_map(
            fn (string $plainCode): array => [
                'user_id' => $userId,
                'code_hash' => $this->hasher->make($plainCode),
                'used_at' => null,
                'created_at' => $issuedAt,
            ],
            $codesPlain,
        ));

        return $codesPlain;
    }

    /**
     * @return list<string>
     */
    private function mint(): array
    {
        // A collision is astronomically rare, but the unique code_hash index
        // would reject the insert outright.
        $codesPlain = [];
        while (count($codesPlain) < self::SHEET_SIZE) {
            $code = $this->generator->generate();
            if (in_array($code, $codesPlain, true)) {
                continue;
            }
            $codesPlain[] = $code;
        }

        return $codesPlain;
    }
}
