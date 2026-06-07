<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/counterparties/{slug}` type-aware profile. Resolves the slug
 * (scoped to the authenticated user) and branches the body into the
 * matching per-type partial under `livewire/profile-tabs/`.
 *
 * Cross-user 404 is enforced at mount-time: a slug owned by another
 * user (or missing) throws `NotFoundHttpException`, so the route
 * resolver renders a 404 — not a 403 — and emits no signal that the
 * slug exists in another user's namespace.
 *
 * Per-type body routing:
 *
 *   - merchant     → profile-tabs/merchant
 *   - personal     → profile-tabs/personal (privacy banner + IBAN reveal)
 *   - bank         → profile-tabs/bank
 *   - government   → profile-tabs/government (tax-year breakdown)
 *   - self_account → profile-tabs/self (stub redirect — no tab bar)
 *   - unknown      → profile-tabs/unknown (no Chains tab, Label CTA)
 *
 * No constructor DI; phpstan-strict-rules bans it on Livewire
 * Component subclasses. Method-parameter DI throughout.
 */
final class CounterpartyProfile extends Component
{
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

        $supportResource = in_array($profile->type, ['merchant', 'government'], true)
            ? $supportResources->forCounterparty($profile->displayName, $profile->type)
            : null;

        return $views->make('counterparties::livewire.counterparty-profile', [
            'profile' => $profile,
            'partial' => $partial,
            'supportResource' => $supportResource,
            'recentActivity' => $query->recentActivity($cpModel, 10),
            'categoryBreakdown' => $query->categoryBreakdown($cpModel),
            'fundingChain' => $query->fundingChainSummary($cpModel),
            'taxYears' => $query->taxYearBreakdown($cpModel),
            'activeTab' => $this->tab,
            'ibanRevealed' => $this->ibanRevealed,
        ]);
    }
}
