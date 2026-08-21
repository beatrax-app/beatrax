<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Events;

final readonly class NotificationPreferenceMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     */
    public function __construct(
        public int $preferenceId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
