<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

final readonly class SavingsPromptDue
{
    /**
     * @param  string  $insightKey  SavingsInsight::$key (e.g. 'cheaper:'.$seriesId), already
     *                              stable and already the occurrence key for the persisted notification — do not
     *                              synthesise a new one downstream
     */
    public function __construct(
        public int $userId,
        public string $insightKey,
        public int $seriesId,
        public string $name,
        public int $monthlyMinor,
        public string $currency,
        public string $message,
        public string $actionUrl,
    ) {}
}
