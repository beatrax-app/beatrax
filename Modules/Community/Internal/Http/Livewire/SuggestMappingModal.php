<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Community\Internal\Services\GitHubCompareUrlBuilder;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Modules\Community\Public\Dto\SuggestMappingDto;
use Modules\Community\Public\Events\MysteryMerchantSubmitted;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * @link ../../../../../.docs/features/community/architecture.md
 */
final class SuggestMappingModal extends Component
{
    public string $pattern = '';

    public string $name = '';

    public ?string $category = null;

    public string $region = 'NL';

    public string $submitError = '';

    #[On('suggest-mapping:open')]
    public function open(string $rawDescription, ?string $name = null, ?string $category = null): void
    {
        $this->pattern = $rawDescription;
        $this->name = $name ?? '';
        $this->category = $category;
        $this->region = 'NL';
        $this->submitError = '';
    }

    public function submit(
        CurrentUser $currentUser,
        OpenExternalUrlAction $openUrl,
        GitHubCompareUrlBuilder $urlBuilder,
        Dispatcher $events,
    ): void {
        $this->submitError = '';

        $pattern = trim($this->pattern);
        $name = trim($this->name);
        if ($pattern === '') {
            $this->submitError = 'Pattern is required.';

            return;
        }
        if ($name === '') {
            $this->submitError = 'Name is required.';

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
            category: $category,
            region: $this->region !== '' ? $this->region : 'NL',
        );

        $url = $urlBuilder->build($dto);

        try {
            $openUrl($url);
        } catch (InvalidArgumentException $e) {
            $this->submitError = $e->getMessage();

            return;
        }

        $events->dispatch(new MysteryMerchantSubmitted(
            userId: $currentUser->user()->id,
            pattern: $pattern,
        ));

        $this->dispatch('toast.show', message: 'Suggestion opened in your browser.');
        $this->dispatch('modal-close', name: 'suggest-mapping');

        $this->pattern = '';
        $this->name = '';
        $this->category = null;
        $this->region = 'NL';
    }

    public function cancel(): void
    {
        $this->dispatch('modal-close', name: 'suggest-mapping');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('community::livewire.suggest-mapping-modal');
    }
}
