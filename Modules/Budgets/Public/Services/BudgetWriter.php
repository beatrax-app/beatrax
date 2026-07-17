<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use InvalidArgumentException;
use Modules\Budgets\Models\CategoryBudget;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;

/**
 * The single write path for category budgets, shared by the /budgets page and
 * the onboarding wizard step. Parses user-entered amounts, validates that a
 * client-supplied category id is actually one the user may budget, and upserts
 * the budget — so neither caller can attach a budget to another user's
 * category or duplicate logic.
 */
final class BudgetWriter
{
    public function __construct(
        private readonly BudgetProgressQuery $query,
    ) {}

    /**
     * Save a monthly EUR budget for ($user, $categoryId). Returns false (no
     * write) when the category is not one the user may budget — see
     * BudgetProgressQuery::canBudget.
     *
     * `$thresholdPercent` (D-20) is the optional per-budget notification
     * threshold: `null` means "use the default"
     * (`BudgetProgressQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT`), any other
     * value must be `1..200`. Throws `InvalidArgumentException` on an
     * out-of-range value rather than silently clamping it — a tampered
     * payload must not persist an out-of-range value.
     */
    public function save(User $user, int $categoryId, int $minor, ?int $thresholdPercent = null): bool
    {
        if ($minor <= 0 || ! $this->query->canBudget($user, $categoryId)) {
            return false;
        }

        self::assertValidThreshold($thresholdPercent);

        // Bypass the current-user global scope so the explicit $user is
        // authoritative: with the scope active, an upsert for any user other
        // than the logged-in one would mismatch and fall through to an INSERT
        // that trips the (user_id, category_id) unique constraint.
        CategoryBudget::query()->withoutGlobalScope(UserScope::class)->updateOrCreate(
            ['user_id' => $user->id, 'category_id' => $categoryId],
            ['budget_minor' => $minor, 'currency' => 'EUR', 'period_type' => 'monthly', 'threshold_percent' => $thresholdPercent],
        );

        return true;
    }

    public function remove(User $user, int $categoryId): void
    {
        CategoryBudget::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->delete();
    }

    /**
     * Persists ONLY the D-20 per-budget notify threshold for an existing
     * budget, without touching its amount/currency/period. `null` clears the
     * explicit threshold back to "use the default". Returns false when no
     * budget row exists for ($user, $categoryId) — the caller cannot set a
     * threshold on a budget that was never created.
     *
     * Cross-user safety (T-18-05): the update is scoped by an explicit
     * `user_id` predicate resolved from `$user`, never trusted from a
     * client-supplied value beyond `$categoryId` itself.
     */
    public function setThreshold(User $user, int $categoryId, ?int $thresholdPercent): bool
    {
        self::assertValidThreshold($thresholdPercent);

        $updated = CategoryBudget::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->update(['threshold_percent' => $thresholdPercent]);

        return $updated > 0;
    }

    /**
     * T-18-04: server-side bounds check `1..200`, mirroring
     * `AnomalySettingsSection::save()`'s "a tampered Livewire payload cannot
     * persist an out-of-range value" discipline. `null` ("use the default")
     * always passes.
     */
    private static function assertValidThreshold(?int $thresholdPercent): void
    {
        if ($thresholdPercent !== null && ($thresholdPercent < 1 || $thresholdPercent > 200)) {
            throw new InvalidArgumentException('Threshold must be between 1 and 200.');
        }
    }

    /**
     * Parse a user-entered amount into positive integer minor units, or null
     * if invalid. Handles the Dutch grouped form "1.234,56" and the plain
     * forms "1234.56" / "50,00" / "50": the rightmost of '.'/',' is the
     * decimal separator and the other is thousands. The whole part is capped
     * at 12 digits so the cents arithmetic cannot overflow PHP_INT_MAX.
     */
    public function parseAmount(string $value): ?int
    {
        $normalised = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($normalised === '') {
            return null;
        }

        $lastDot = strrpos($normalised, '.');
        $lastComma = strrpos($normalised, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $normalised = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $normalised)
                : str_replace(',', '', $normalised);
        } elseif ($lastComma !== false) {
            $normalised = str_replace(',', '.', $normalised);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalised) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $normalised, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $minor > 0 ? $minor : null;
    }
}
