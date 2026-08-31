<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Enums;

// Which suggestion the savings card is making. The dismissal key is derived
// here rather than composed at each call site: it crosses the wire back from
// the card and is persisted, so the writer needs one place to recognise it.
enum SavingsInsightKind: string
{
    case Cheaper = 'cheaper';

    case Cancel = 'cancel';

    case Review = 'review';

    public function keyFor(int $seriesId): string
    {
        return $this->value.':'.$seriesId;
    }

    // The copy a kind renders, spelled out rather than composed from the case
    // value so a sweep for unreferenced translation keys can still see it.
    public function messageKey(): string
    {
        return match ($this) {
            self::Cheaper => 'drift-alerts::savings.insight.cheaper_message',
            self::Cancel => 'drift-alerts::savings.insight.cancel_message',
            self::Review => 'drift-alerts::savings.insight.review_message',
        };
    }

    public function actionKey(): string
    {
        return match ($this) {
            self::Cheaper => 'drift-alerts::savings.insight.cheaper_action',
            self::Cancel => 'drift-alerts::savings.insight.cancel_action',
            self::Review => 'drift-alerts::savings.insight.review_action',
        };
    }

    // Null for anything this enum could not have written, which is what the
    // dismissal writer refuses.
    public static function tryParse(string $key): ?self
    {
        $parts = explode(':', $key, 2);
        if (count($parts) !== 2 || $parts[1] === '' || ! ctype_digit($parts[1])) {
            return null;
        }

        return self::tryFrom($parts[0]);
    }
}
