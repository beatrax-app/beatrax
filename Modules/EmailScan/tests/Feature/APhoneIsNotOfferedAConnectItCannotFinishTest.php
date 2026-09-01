<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;

// The page tells the phone that mailboxes are connected in the desktop app,
// and then offered two Connect buttons anyway. Both ends of that offer are
// unreachable here: the client wizard prints a loopback callback URL, and the
// dance itself redirects to one — and this runtime serves the app over its own
// scheme with nothing listening on that port.
//
// $onPhone already withheld Scan now for a different reason, so the shape was
// established and the connect controls were simply missed.

function connectPhoneUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

afterEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
});

it('refuses to open the client wizard where the callback cannot arrive', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::actingAs(connectPhoneUser('connect-refused'))
        ->test(InboxesPage::class)
        ->call('openWizard', 'gmail')
        ->assertNotDispatched('oauth-client-wizard:open');
});

it('still opens it on a desktop, so the guard has not closed both', function (): void {
    Livewire::actingAs(connectPhoneUser('connect-allowed'))
        ->test(InboxesPage::class)
        ->call('openWizard', 'gmail')
        ->assertDispatched('oauth-client-wizard:open', provider: 'gmail');
});

it('does not draw a connect button the phone cannot follow', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::actingAs(connectPhoneUser('connect-nobutton'))
        ->test(InboxesPage::class)
        ->assertDontSee("openWizard('gmail')", false)
        ->assertDontSee("openWizard('microsoft')", false);
});

it('still draws it on a desktop, so the button is withheld and not deleted', function (): void {
    Livewire::actingAs(connectPhoneUser('connect-button'))
        ->test(InboxesPage::class)
        ->assertSee("openWizard('gmail')", false)
        ->assertSee("openWizard('microsoft')", false);
});

it('says where connecting does work rather than failing silently', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::actingAs(connectPhoneUser('connect-told'))
        ->test(InboxesPage::class)
        ->assertSee('This phone does not scan mailboxes');
});
