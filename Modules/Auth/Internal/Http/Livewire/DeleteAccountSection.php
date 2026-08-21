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
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;
use Throwable;

// The in-app deletion path both app stores require. It asks for the password
// because a household shares this device and anyone signed in reaches settings.
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
            // Cleared before it propagates: the component survives a failure
            // into the wire snapshot, and a mistyped password is a near-miss.
            $this->password = '';

            // Rethrown so Livewire maps it onto the field's error bag.
            throw $e;
        } catch (Throwable $e) {
            $this->password = '';

            // DeleteAccountAction swallows everything past the commit, so this
            // message cannot claim a rollback that did not happen.
            $log->error('DeleteAccountSection: account deletion failed and was rolled back.', SafeExceptionContext::describe($e));

            $this->failure = Lang::get('auth::delete_account.error_failed');

            return;
        }

        // The whole session is gone, so a Livewire navigation would re-use page
        // state with no account behind it.
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

    // Must mirror DeleteAccountAction's successor choice.
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
