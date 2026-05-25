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
 * Global suggest-mapping modal SFC. Mounted once at the layout level so
 * the `suggest-mapping:open` Livewire event can open the modal from
 * anywhere — the Triage row CTA, the `/community/mystery-merchants`
 * card, and the Settings → Shared list "Browse mystery merchants"
 * button all dispatch the same event.
 *
 * The modal renders a small form (read-only mono pattern + editable
 * name + optional category + region dropdown), a live-updating YAML
 * preview pane that mirrors the four fields, and a primary "Submit as
 * draft PR" button. On submit the action layer builds a GitHub Compare
 * URL via GitHubCompareUrlBuilder, hands it to OpenExternalUrlAction
 * (which validates the https + allow-listed host posture and then
 * delegates to Native\Desktop\Contracts\Shell::openExternal), and
 * dispatches a MysteryMerchantSubmitted event via the method-injected
 * Dispatcher.
 *
 * Service collaborators arrive as parameters on action methods — never
 * via constructor injection. The Livewire strict-rules ruleset
 * forbids constructor-DI on Component subclasses.
 *
 * On OpenExternalUrlAction throwing (e.g. a tampered config value
 * pointed at a non-allow-listed host) the modal stays open and the
 * error message is rendered inline above the footer; the user can
 * close the modal and re-open without re-typing.
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
        $this->dispatch('modal-hide', name: 'suggest-mapping');

        $this->pattern = '';
        $this->name = '';
        $this->category = null;
        $this->region = 'NL';
    }

    public function cancel(): void
    {
        $this->dispatch('modal-hide', name: 'suggest-mapping');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('community::livewire.suggest-mapping-modal');
    }
}
