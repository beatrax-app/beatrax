<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Mobile\Internal\Notifications\NotificationGrantRecord;
use Modules\Notifications\Public\Contracts\SystemNotificationConsent;
use Modules\Notifications\Public\Enums\SystemNotificationGrant;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;

// Mounted in the application layout on the phone, because the prompt has to be
// raised somewhere every reader passes. It was raised in one place only —
// saving the notification settings form — while the shipped defaults have two
// triggers ON, so a reader who never opened it got rows and no banner.

// The answer comes back as a DOM event the shell injects into the page, not as
// a PHP event, which is why this is a component and not a listener.
final class MobileNotificationPermission extends Component
{
    // The event name the shell dispatches on `native-event`. Held as a
    // constant rather than written into the view so the two halves of the
    // comparison cannot drift apart in escaping.
    public const string GRANT_EVENT = 'NativePHP\\LocalNotifications\\Events\\PermissionGranted';

    #[Locked]
    public bool $askOnLoad = false;

    public function mount(
        CurrentUser $currentUser,
        NotificationGrantRecord $record,
        NotificationPreferenceQuery $prefs,
    ): void {
        $this->askOnLoad = $this->shouldAsk($currentUser, $record, $prefs);
    }

    // Called from the page as soon as it loads, never from a control the
    // reader presses: iOS and Android each show their dialog once per install
    // and answer silently ever after, so the cost of an ask that turns out to
    // be redundant is a bridge call and nothing on screen.
    public function askTheDevice(
        CurrentUser $currentUser,
        NotificationGrantRecord $record,
        SystemNotificationConsent $consent,
    ): void {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        // Stamped first. A reader who backgrounds the app while the dialog is
        // up leaves no answer behind, and an unstamped ask would raise it
        // again on the next page.
        $record->markAsked($currentUser->id());

        $consent->request();
    }

    public function recordDeviceAnswer(
        bool $granted,
        CurrentUser $currentUser,
        NotificationGrantRecord $record,
    ): void {
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $record->recordAnswer($currentUser->id(), $granted);
        $this->askOnLoad = false;
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('mobile::livewire.mobile-notification-permission', [
            'grantEvent' => self::GRANT_EVENT,
        ]);
    }

    // Awaiting re-asks on purpose: the platform answers a second ask from the
    // settled value without showing anything, so an answer lost to a page
    // that navigated away is recovered rather than remembered as a refusal.
    private function shouldAsk(
        CurrentUser $currentUser,
        NotificationGrantRecord $record,
        NotificationPreferenceQuery $prefs,
    ): bool {
        if (! $currentUser->isAuthenticated()) {
            return false;
        }

        $state = $record->state($currentUser->id());

        if ($state !== SystemNotificationGrant::NeverAsked && $state !== SystemNotificationGrant::Awaiting) {
            return false;
        }

        return $this->anythingWouldBeDelivered($prefs, $currentUser);
    }

    // Nothing is asked of a reader who has switched every trigger off: the
    // dialog is a one-shot per install, and spending it on an app that would
    // post nothing is how a later yes becomes impossible.
    private function anythingWouldBeDelivered(NotificationPreferenceQuery $prefs, CurrentUser $currentUser): bool
    {
        $preferences = $prefs->forCurrentDevice($currentUser->user());

        return $preferences->remindersEnabled
            || $preferences->budgetNudgesEnabled
            || $preferences->savingsPromptsEnabled
            || $preferences->digestCadence !== DigestCadence::Off;
    }
}
