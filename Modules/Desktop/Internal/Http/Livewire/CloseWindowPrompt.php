<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;

// Keeping the app in the tray is the calmer default because it keeps
// the bundled queue worker and the timer-based email scan alive —
// closing entirely silently stops both. The default-focused emerald
// "keep in tray" button (vs. the rose "quit") reinforces this.
final class CloseWindowPrompt extends Component
{
    public const MODAL_NAME = 'close-window-prompt';

    public bool $rememberChoice = true;

    public function mount(): void
    {
        // The close-prompt route is only ever navigated to on a first
        // close; without this dispatch the modal element would render
        // invisibly. Flux's modal listens for this event by name.
        $this->dispatch('modal-show', name: self::MODAL_NAME);
    }

    public function chooseKeepInTray(CurrentUser $currentUser, WindowCloseBehavior $behavior): void
    {
        if ($this->rememberChoice) {
            $behavior->persistChoice($currentUser->user(), WindowCloseBehavior::CHOICE_TRAY);
        }

        $this->dispatch('close-window-choice', choice: WindowCloseBehavior::CHOICE_TRAY);
        $this->dispatch('modal-close', name: self::MODAL_NAME);
    }

    public function chooseQuit(CurrentUser $currentUser, WindowCloseBehavior $behavior): void
    {
        if ($this->rememberChoice) {
            $behavior->persistChoice($currentUser->user(), WindowCloseBehavior::CHOICE_QUIT);
        }

        $this->dispatch('close-window-choice', choice: WindowCloseBehavior::CHOICE_QUIT);
        $this->dispatch('modal-close', name: self::MODAL_NAME);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('desktop::close-window-prompt', [
            'title' => Lang::get('desktop::screens.close.title'),
            'body' => Lang::get('desktop::screens.close.body'),
            'buttonQuit' => Lang::get('desktop::screens.close.button_quit'),
            'buttonKeepInTray' => Lang::get('desktop::screens.close.button_keep_in_tray'),
            'checkboxRemember' => Lang::get('desktop::screens.close.checkbox_remember'),
            'modalName' => self::MODAL_NAME,
        ]);
    }
}
