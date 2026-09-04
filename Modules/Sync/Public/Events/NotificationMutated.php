<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

final readonly class NotificationMutated
{
    /**
     * @param  string  $mutationType  'create' | 'edit' | 'delete'
     * @param  array<string, mixed>  $dirtyFields  Changed field => new-value map.
     */
    public function __construct(
        public string $notificationId,
        public int $userId,
        public string $mutationType,
        public array $dirtyFields = [],
    ) {}
}
