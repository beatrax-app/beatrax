<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;

// Keeping the app in the tray is the calmer default because it keeps
// the bundled queue worker and the timer-based email scan alive —
// closing entirely silently stops both. The default-focused emerald
// "keep in tray" button (vs. the rose "quit") reinforces this.
final class CloseWindowPrompt extends Component
{
    public const TITLE = 'Keep beatrax running?';

    public const BODY = 'Closing the window can either quit beatrax completely or keep it running quietly in the menu bar so scheduled email scans continue.';

    public const BUTTON_QUIT = 'Quit beatrax';

    public const BUTTON_KEEP_IN_TRAY = 'Keep running in the tray';

    public const CHECKBOX_REMEMBER = 'Remember my choice';

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
            'title' => self::TITLE,
            'body' => self::BODY,
            'buttonQuit' => self::BUTTON_QUIT,
            'buttonKeepInTray' => self::BUTTON_KEEP_IN_TRAY,
            'checkboxRemember' => self::CHECKBOX_REMEMBER,
            'modalName' => self::MODAL_NAME,
        ]);
    }
}
