<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Community\Internal\Services\ContributionLog;
use Modules\Community\Internal\Services\GitHubCompareUrlBuilder;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Community\Public\Dto\SuggestMappingDto;
use Modules\Community\Public\Events\MysteryMerchantSubmitted;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\Lang;

final class SuggestMappingModal extends Component
{
    use DispatchesToast;

    public string $pattern = '';

    public string $name = '';

    public ?string $category = null;

    public string $region = '';

    public string $submitError = '';

    public function mount(CurrentUser $currentUser, UserCountry $countries): void
    {
        $this->region = self::regionFor($currentUser, $countries);
    }

    #[On('suggest-mapping:open')]
    public function open(
        CurrentUser $currentUser,
        UserCountry $countries,
        string $rawDescription,
        ?string $name = null,
        ?string $category = null,
    ): void {
        $this->pattern = $rawDescription;
        $this->name = $name ?? '';
        $this->category = $category;
        $this->region = self::regionFor($currentUser, $countries);
        $this->submitError = '';
    }

    public function submit(
        CurrentUser $currentUser,
        UserCountry $countries,
        OpenExternalUrlAction $openUrl,
        GitHubCompareUrlBuilder $urlBuilder,
        Dispatcher $events,
        ContributionLog $contributions,
    ): void {
        $this->submitError = '';

        $pattern = trim($this->pattern);
        $name = trim($this->name);
        if ($pattern === '') {
            $this->submitError = Lang::get('community::suggest.errors.pattern_required');

            return;
        }
        if ($name === '') {
            $this->submitError = Lang::get('community::suggest.errors.name_required');

            return;
        }

        $category = $this->category;
        if (is_string($category)) {
            $category = trim($category);
            if ($category === '') {
                $category = null;
            }
        }

        $dto = new SuggestMappingDto(
            pattern: $pattern,
            name: $name,
            region: $this->region,
            category: $category,
        );

        $url = $urlBuilder->build($dto);

        try {
            $openUrl($url);
        } catch (InvalidArgumentException $e) {
            $this->submitError = $e->getMessage();

            return;
        }

        $contributions->record($currentUser->user()->id, $currentUser->user()->username, $dto);

        $events->dispatch(new MysteryMerchantSubmitted(
            userId: $currentUser->user()->id,
            pattern: $pattern,
        ));

        $this->toast(Lang::get('community::suggest.toast'));
        $this->dispatch('modal-close', name: 'suggest-mapping');

        $this->pattern = '';
        $this->name = '';
        $this->category = null;
        $this->region = self::regionFor($currentUser, $countries);
    }

    public function cancel(): void
    {
        $this->dispatch('modal-close', name: 'suggest-mapping');
    }

    public function render(ViewFactory $views, UserCountry $countries): View
    {
        return $views->make('community::livewire.suggest-mapping-modal', [
            'regionOptions' => self::regionOptions($countries),
        ]);
    }

    // The corpus stores the region upper-cased and CommunityCorpusQuery scopes
    // a read to it, so a suggestion filed under any other code never comes back
    // to the reader who made it.
    private static function regionFor(CurrentUser $currentUser, UserCountry $countries): string
    {
        return strtoupper($countries->current($currentUser->id()));
    }

    /**
     * @return array<string, string>
     */
    private static function regionOptions(UserCountry $countries): array
    {
        $options = [];
        foreach ($countries->options() as $code => $label) {
            $options[strtoupper($code)] = $label;
        }

        return $options;
    }
}
