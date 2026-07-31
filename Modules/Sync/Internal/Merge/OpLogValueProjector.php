<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Internal\Merge\Strategies\GCounterStrategy;
use Modules\Sync\Internal\Merge\Strategies\LwwPerFieldStrategy;
use Modules\Sync\Internal\Merge\Strategies\MergeStrategyInterface;
use Modules\Sync\Internal\Merge\Strategies\OrSetStrategy;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogValueProjector
{
    private const string DEFAULT_STRATEGY = 'lww';

    private const string STRATEGY_G_COUNTER = 'g_counter';

    private const string STRATEGY_OR_SET = 'or_set';

    /** @var array<string, MergeStrategyInterface> */
    private array $strategies;

    public function __construct(
        private MergeRulesRegistry $rules,
        private SensitiveFieldRegistry $sensitiveFields,
        private ?SensitiveColumnCodec $columnCodec = null,
        private ?Session $session = null,
    ) {
        $this->strategies = [
            self::DEFAULT_STRATEGY => new LwwPerFieldStrategy,
            self::STRATEGY_G_COUNTER => new GCounterStrategy,
            self::STRATEGY_OR_SET => new OrSetStrategy,
        ];
    }

    // LWW/G-Counter bind directly; OR-Set resolves to a list<array{v,tag}>,
    // which a query-builder bind cannot accept ("Array to string
    // conversion"), so any non-scalar, non-null result is JSON-encoded.
    /**
     * @throws \JsonException If a non-scalar value cannot be JSON-encoded; the
     *                        caller catches this and routes the op to quarantine.
     */
    public function encodeColumnValue(mixed $resolved): mixed
    {
        if ($resolved === null || is_scalar($resolved)) {
            return $resolved;
        }

        return json_encode($resolved, JSON_THROW_ON_ERROR);
    }

    // A non-scalar strategy result (OR-Set -> list<array>) is JSON-encoded by
    // the caller before it reaches a SQLite column; the projection column is
    // then re-encrypted under the CURRENT epoch (rotation-safe) — the strategy
    // itself only ever saw plaintext.
    public function resolveStrategy(string $table, string $field): MergeStrategyInterface
    {
        return $this->strategies[$this->rules->strategyFor($table, $field)]
            ?? $this->strategies[self::DEFAULT_STRATEGY];
    }

    // Re-encrypts a plaintext sensitive-field value for the PROJECTION
    // column write-back (rotation-safe, current-epoch AD) so it stays
    // ciphertext at rest. Pass-through when the field isn't sensitive, the
    // value isn't a string, or the codec/encryption isn't usable for $userId.
    public function reencryptForProjection(string $table, string $field, mixed $value, int $userId): mixed
    {
        if ($this->columnCodec === null || $this->session === null || ! is_string($value)) {
            return $value;
        }

        if (! $this->sensitiveFields->isSensitive($table, $field)) {
            return $value;
        }

        return $this->columnCodec->encryptValue($table, $field, $value, $userId, $this->session);
    }
}
