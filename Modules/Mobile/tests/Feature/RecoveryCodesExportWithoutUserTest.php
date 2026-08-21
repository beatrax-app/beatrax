<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Internal\Http\Controllers\RecoveryCodesExportController;

uses(RefreshDatabase::class);

// The route sits outside the auth group on purpose — the screen that shows the
// codes once had a Livewire round-trip 419 on device and take them with it, so
// holding the session that holds the codes is the gate. That makes reaching it
// with no bound user a state the route invites rather than forbids, and the
// only coverage it had always acted as a signed-in user.
it('does not export the codes when no user is bound, and leaves them in the session', function (): void {
    $codes = ['ABCD-EFGH-JKLM-NPQR-STUV'];

    $this->withSession([RecoveryCodesExportController::SESSION_KEY => $codes]);

    $response = $this->getJson(route('mobile.recovery-codes.export'));

    // Not a specific status: the point is that it does not report success. The
    // caller reads `saved` out of the JSON body and treats a parse failure as a
    // failed save, so any non-2xx leaves the user with an honest error.
    expect($response->getStatusCode())->toBeGreaterThanOrEqual(300);

    // The codes are shown exactly once. A refusal that consumed them would lose
    // the user their account recovery with no way back.
    expect(session(RecoveryCodesExportController::SESSION_KEY))->toBe($codes);
});
