<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Cross-user 404 is enforced at mount time (throws NotFoundHttpException,
// not 403) so the route resolver never signals that a slug exists in
// another user's namespace. The body then routes to the profile-tabs
// partial matching the resolved profile's type.
/**
 * @link ../../../../../.docs/features/counterparties/architecture.md
 */
final class CounterpartyProfile extends Component
{
    use HandlesTaxTagging;

    public string $slug = '';

    public string $tab = 'overview';

    public bool $ibanRevealed = false;

    public function mount(string $slug, CurrentUser $currentUser, CounterpartyProfileQuery $query): void
    {
        $this->slug = $slug;

        $profile = $query->bySlug($currentUser->user(), $slug);
        if ($profile === null) {
            throw new NotFoundHttpException('Counterparty not found.');
        }
    }

    public function switchTab(string $tab): void
    {
        $allowed = ['overview', 'transactions', 'transfers', 'entries', 'payments', 'chains', 'tax-years', 'aliases'];
        if (in_array($tab, $allowed, true)) {
            $this->tab = $tab;
        }
    }

    public function toggleIban(): void
    {
        $this->ibanRevealed = ! $this->ibanRevealed;
    }

    public function render(
        CurrentUser $currentUser,
        CounterpartyProfileQuery $query,
        ViewFactory $views,
        SupportResourceProvider $supportResources,
        RecurringSeriesQuery $recurring,
        TaxTagQuery $taxTagQuery,
    ): View {
        $user = $currentUser->user();
        $profile = $query->bySlug($user, $this->slug);

        // Belt-and-suspenders re-check — the cross-user invariant is
        // enforced at mount time, but the render cycle re-runs after
        // every wire action so the read is also re-guarded here.
        if ($profile === null) {
            throw new NotFoundHttpException('Counterparty not found.');
        }

        $cpModel = Counterparty::query()
            ->where('user_id', $user->id)
            ->where('id', $profile->id)
            ->firstOrFail();

        $partial = match ($profile->type) {
            'merchant' => 'counterparties::livewire.profile-tabs.merchant',
            'personal' => 'counterparties::livewire.profile-tabs.personal',
            'bank' => 'counterparties::livewire.profile-tabs.bank',
            'government' => 'counterparties::livewire.profile-tabs.government',
            'self_account' => 'counterparties::livewire.profile-tabs.self',
            default => 'counterparties::livewire.profile-tabs.unknown',
        };

        $supportResource = in_array($profile->type, [CounterpartyType::Merchant->value, CounterpartyType::Government->value], true)
            ? $supportResources->forCounterparty($profile->displayName, $profile->type)
            : null;

        $recurringSeries = in_array($profile->type, [CounterpartyType::Merchant->value, CounterpartyType::Bank->value, CounterpartyType::Government->value], true)
            ? $recurring->approvedSeriesForCounterparty($profile->id, $user)
            : [];

        // Batch-loads tax-tag state for every recent-activity row in one
        // query rather than one per row, avoiding an N+1 pattern when the
        // tax badge renders across the whole list.
        $recentActivity = $query->recentActivity($cpModel, 10);
        $recentIds = array_map(static fn (object $row): int => is_numeric($row->id) ? (int) $row->id : 0, $recentActivity->all());
        $taxState = $this->taxTagStateFor($recentIds, $taxTagQuery, $currentUser);

        return $views->make('counterparties::livewire.counterparty-profile', [
            'profile' => $profile,
            'partial' => $partial,
            'supportResource' => $supportResource,
            'recurringSeries' => $recurringSeries,
            'recentActivity' => $recentActivity,
            'categoryBreakdown' => $query->categoryBreakdown($cpModel),
            'fundingChain' => $query->fundingChainSummary($cpModel),
            'taxYears' => $query->taxYearBreakdown($cpModel),
            'activeTab' => $this->tab,
            'ibanRevealed' => $this->ibanRevealed,
            'taxState' => $taxState,
        ]);
    }
}
