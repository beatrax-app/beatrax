<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Events;

final readonly class SavingsPromptDue
{
    /**
     * @param  string  $insightKey  SavingsInsight::$key (e.g. 'cheaper:'.$seriesId), already
     *                              stable and already the occurrence key for the persisted notification — do not
     *                              synthesise a new one downstream
     * @param  string  $messageKey  the sentence's translation key, not the sentence: this is emitted
     *                              by an hourly job that holds no reader's language, and the row it
     *                              becomes is read for as long as the retention window
     *
     * No action URL: the insight's action is the merchant's own cancellation
     * page, and the notification this becomes deep-links the reader into the
     * screen that offers it rather than carrying an outside address across a
     * module boundary and into the application window's own address bar.
     */
    public function __construct(
        public int $userId,
        public string $insightKey,
        public int $seriesId,
        public string $name,
        public int $monthlyMinor,
        public string $currency,
        public string $messageKey,
    ) {}
}
