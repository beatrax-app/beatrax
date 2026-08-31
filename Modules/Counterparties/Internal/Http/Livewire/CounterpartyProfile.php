<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserCountry;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// mount() raises a 404, never a 403, on another user's slug: a 403 would
// confirm the slug exists in someone else's namespace.
final class CounterpartyProfile extends Component
{
    use HandlesTaxTagging;

    public string $slug = '';

    public string $tab = 'overview';

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

    public function render(
        CurrentUser $currentUser,
        CounterpartyProfileQuery $query,
        ViewFactory $views,
        SupportResourceProvider $supportResources,
        RecurringSeriesQuery $recurring,
        TaxTagQuery $taxTagQuery,
        UserCountry $countries,
    ): View {
        $user = $currentUser->user();
        $profile = $query->bySlug($user, $this->slug);

        // render() re-runs after every wire action, so the mount-time cross-user
        // guard is re-asserted here rather than trusted once.
        if ($profile === null) {
            throw new NotFoundHttpException('Counterparty not found.');
        }

        $cpModel = Counterparty::query()
            ->where('user_id', $user->id)
            ->where('id', $profile->id)
            ->firstOrFail();

        $partial = match (CounterpartyType::tryFrom($profile->type)) {
            CounterpartyType::Merchant => 'counterparties::livewire.profile-tabs.merchant',
            CounterpartyType::Personal => 'counterparties::livewire.profile-tabs.personal',
            CounterpartyType::Bank => 'counterparties::livewire.profile-tabs.bank',
            CounterpartyType::Government => 'counterparties::livewire.profile-tabs.government',
            CounterpartyType::SelfAccount => 'counterparties::livewire.profile-tabs.self',
            default => 'counterparties::livewire.profile-tabs.unknown',
        };

        // Scoped to the user's country: brands recur across markets under one
        // name (Sanitas is a Swiss insurer and a Spanish provider), and an
        // unscoped lookup handed whichever file sorted last to everybody.
        // Empty means "not set" and searches everywhere, as before.
        $countryCode = $countries->current($user->id);
        $supportCountry = $countryCode === '' ? null : $countryCode;

        $supportResource = in_array($profile->type, [CounterpartyType::Merchant->value, CounterpartyType::Government->value], true)
            ? $supportResources->forCounterparty($profile->displayName, $profile->type, $supportCountry)
            : null;

        $recurringSeries = in_array($profile->type, [CounterpartyType::Merchant->value, CounterpartyType::Bank->value, CounterpartyType::Government->value], true)
            ? $recurring->approvedSeriesForCounterparty($profile->id, $user)
            : [];

        $recentActivity = $query->recentActivity($cpModel, 10);
        $recentIds = array_map(static fn (object $row): int => is_numeric($row->id) ? (int) $row->id : 0, $recentActivity->all());
        $taxState = $this->taxTagStateFor($recentIds, $taxTagQuery, $currentUser);

        return $views->make('counterparties::livewire.counterparty-profile', [
            'profile' => $profile,
            'partial' => $partial,
            'supportResource' => $supportResource,
            'recurringSeries' => $recurringSeries,
            'recentActivity' => $recentActivity,
            'categoryBreakdown' => $query->categoryBreakdown($cpModel, $user),
            'fundingChain' => $query->fundingChainSummary($cpModel),
            'taxYears' => $query->taxYearBreakdown($cpModel, $user),
            'activeTab' => $this->tab,
            'taxState' => $taxState,
        ]);
    }
}
