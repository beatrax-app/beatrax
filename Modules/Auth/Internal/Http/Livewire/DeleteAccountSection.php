<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Auth\Public\Actions\DeleteAccountAction;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;
use Throwable;

// The in-app account-deletion path both stores require. Confirmation is
// two-step and asks for the password, because this is a device a household
// shares and the button sits on a settings page anyone signed in can reach. It
// names the paired devices too, since deletion here cannot reach them.
final class DeleteAccountSection extends Component
{
    public string $password = '';

    public bool $confirming = false;

    public ?string $failure = null;

    public function startConfirm(): void
    {
        $this->confirming = true;
        $this->failure = null;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->confirming = false;
        $this->password = '';
        $this->failure = null;
        $this->resetErrorBag();
    }

    public function deleteAccount(
        DeleteAccountAction $deleteAccount,
        CurrentUser $currentUser,
        LoggerInterface $log,
    ): void {
        $this->failure = null;

        try {
            $deleteAccount($currentUser->user(), $this->password);
        } catch (ValidationException $e) {
            // Cleared before it propagates: a failure leaves the component
            // alive and re-serialised into the browser's wire snapshot, and a
            // mistyped password would sit there as a near-miss of the real one.
            $this->password = '';

            // A wrong password is the user's answer to a question, not a
            // failure — Livewire maps it onto the field's error bag.
            throw $e;
        } catch (Throwable $e) {
            $this->password = '';

            // Only pre-commit failures reach here: DeleteAccountAction logs
            // and swallows everything past the commit, precisely so this
            // message cannot claim a rollback that did not happen.
            $log->error('DeleteAccountSection: account deletion failed and was rolled back.', [
                'exception' => $e->getMessage(),
            ]);

            $this->failure = Lang::get('auth::delete_account.error_failed');

            return;
        }

        // navigate: false because the whole session is gone — a Livewire
        // navigation would re-use a page state that no longer has an account
        // behind it. The first-run middleware decides where a guest lands.
        $this->redirect('/', navigate: false);
    }

    public function render(ViewFactory $views, CurrentUser $currentUser, DatabaseManager $db, DeviceRegistryService $devices): View
    {
        $user = $currentUser->user();

        return $views->make('auth::livewire.delete-account-section', [
            'pairedDeviceNames' => $devices->otherDeviceNames($user->id),
            'successorUsername' => $this->successorUsername($db, $user->id, $user->is_developer === true),
        ]);
    }

    // Whom the device is left to, when this account is its only administrator
    // and somebody else is still on it. Mirrors DeleteAccountAction's choice.
    private function successorUsername(DatabaseManager $db, int $userId, bool $isAdministrator): ?string
    {
        if (! $isAdministrator) {
            return null;
        }

        $others = $db->connection()->table('users')->where('id', '!=', $userId);

        if ((clone $others)->where('is_developer', true)->exists()) {
            return null;
        }

        $username = (clone $others)->orderBy('id')->value('username');

        return is_string($username) ? $username : null;
    }
}
