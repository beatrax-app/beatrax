<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * @link ../../../../../.docs/features/migration/architecture.md
 */
final class MigrationsIndex extends Component
{
    public function render(ViewFactory $views, CurrentUser $currentUser, DatabaseManager $db): View
    {
        $userId = $currentUser->user()->id;

        // Raw DatabaseManager read (never a chained dynamic Eloquent
        // ->orderByDesc() call) — mirrors PreviewSummaryBuilder/
        // GoalProgressQuery's established discipline against
        // phpstan-strict-rules' staticMethod.dynamicCall false positive.
        $runs = $db->connection()->table('migration_runs')
            ->where('user_id', $userId)
            ->where('status', '!=', 'discarded')
            ->orderByDesc('id')
            ->get(['id', 'source_product', 'status', 'created_at']);

        return $views->make('migration::livewire.migrations-index', [
            'runs' => $runs,
        ]);
    }

    public function formatLabel(string $sourceProduct): string
    {
        return match ($sourceProduct) {
            'ynab4' => 'YNAB4',
            'nynab' => 'New YNAB',
            'actual' => 'Actual Budget',
            default => $sourceProduct,
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'parsed' => 'Parsed',
            'confirmed' => 'Confirmed',
            'needs_attention' => 'Needs attention',
            default => $status,
        };
    }
}
