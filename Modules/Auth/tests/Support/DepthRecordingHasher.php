<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;

// Counts the transaction depth every hash is asked at. Wraps the real hasher
// rather than faking it: the comparison has to keep working, and what is being
// asserted is only where it happens.
final class DepthRecordingHasher implements Hasher
{
    /** @var list<int> */
    public array $depths = [];

    public function __construct(private readonly Hasher $inner, private readonly DatabaseManager $db) {}

    /** @param array<string, mixed> $options */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        $this->record();

        return $this->inner->make($value, $options);
    }

    /** @param array<string, mixed> $options */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->record();

        return $this->inner->check($value, $hashedValue, $options);
    }

    /** @param array<string, mixed> $options */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }

    /** @return array<string, mixed> */
    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    private function record(): void
    {
        $this->depths[] = $this->db->connection()->transactionLevel();
    }
}
