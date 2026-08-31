<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

// Actual keeps its budgeting mode in preferences.budgetType, and each mode
// stores its months in its own table. Older files still spell the pair
// 'rollover'/'report'.
enum ActualBudgetType: string
{
    case Envelope = 'envelope';

    case Tracking = 'tracking';

    public static function fromPreference(string $value): ?self
    {
        return match (mb_strtolower(trim($value))) {
            'envelope', 'rollover' => self::Envelope,
            'tracking', 'report' => self::Tracking,
            default => null,
        };
    }

    public function budgetTable(): string
    {
        return match ($this) {
            self::Envelope => 'zero_budgets',
            self::Tracking => 'reflect_budgets',
        };
    }
}
