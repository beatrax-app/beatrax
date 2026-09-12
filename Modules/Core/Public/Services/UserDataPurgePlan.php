<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Modules\Core\Internal\Storage\UserDataLocations;

// The paths a deletion removes, read off the one inventory of where this
// install keeps the reader's data. Public because the deletion lives in Auth
// and the inventory is Core's: the alternative was a second list in the other
// module, and what the two disagreed about was every statement ever imported.
/**
 * @link ../../../../.docs/features/core/one-export-action.md#the-boundary-is-a-list-not-a-sweep
 */
final readonly class UserDataPurgePlan
{
    /**
     * @return array<string, list<string>> location key => absolute paths this account owns
     */
    public function forAccount(int $userId): array
    {
        return UserDataLocations::accountScoped($userId);
    }

    /**
     * @return array<string, list<string>> location key => absolute paths that go with the last account
     */
    public function deviceWide(): array
    {
        return UserDataLocations::deviceWide();
    }

    // The half a deletion is not finished without. A peer can put rows back
    // and it cannot put these back, so a survivor here is owed until a sweep
    // clears it, where a surviving artefact is disclosure and logged as such.
    /**
     * @return list<string>
     */
    public function keyMaterialLocations(): array
    {
        return UserDataLocations::keyMaterialLocations();
    }
}
