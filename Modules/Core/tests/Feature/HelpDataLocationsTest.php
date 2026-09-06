<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\HelpDataLocations;
use Modules\Core\Internal\Storage\UserDataLocations;
use Modules\Core\Models\User;

function hdlUser(bool $isDeveloper, string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('returns 200 and renders the verbatim "Where is my data?" page title', function (): void {
    $user = hdlUser(false, 'hdl-title');

    $response = $this->actingAs($user)->get('/help/data-locations');

    $response->assertOk();
    $response->assertSeeText('Where is my data?');
});

it('renders the verbatim local-only intro paragraph', function (): void {
    $user = hdlUser(false, 'hdl-intro');

    Livewire::actingAs($user)->test(HelpDataLocations::class)
        ->assertSeeText('Beatrax stores everything on this device. There is no Beatrax server and no cloud account. One call goes out on its own — a check for a new version, which you can turn off. Everything else waits for you: a mailbox, a bank through Enable Banking, a daily exchange-rate lookup, the devices you pair for sync, a relay you configure, and any link you click. Each one says so on the screen where you turn it on.');
});

it('shows every durable location the inventory holds, resolved', function (): void {
    $user = hdlUser(false, 'hdl-paths');
    $locations = UserDataLocations::all();

    expect($locations)->toHaveCount(7);

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    foreach ($locations as $key => $path) {
        $component->assertSee($path);
        $component->assertSee('data-testid="path-row-'.$key.'"', escape: false);
    }
});

it('gives every location its own aria-labelled copy button', function (): void {
    $user = hdlUser(false, 'hdl-aria');
    $locations = UserDataLocations::all();

    expect($locations)->toHaveCount(7);

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSee('aria-label="Copy database path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy imported statements path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy scanned mail path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy watched drop folder path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy backups path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy connector credentials path to clipboard"', escape: false);
    $component->assertSee('aria-label="Copy logs path to clipboard"', escape: false);
});

it('says the source documents are outside the backup and names their folders', function (): void {
    $user = hdlUser(false, 'hdl-artefacts');
    $artefacts = UserDataLocations::artefacts();

    expect($artefacts)->toHaveCount(3);

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSeeText('Your source documents are not inside the backup');
    $component->assertSeeText('A backup holds the database and nothing else.');

    foreach ($artefacts as $path) {
        $component->assertSee($path);
    }
});

it('offers the export to every reader, not only one with Dev Mode on', function (): void {
    $component = Livewire::actingAs(hdlUser(false, 'hdl-export-off'))->test(HelpDataLocations::class);

    $component->assertSeeText('Export everything');
    $component->assertSeeText('Export everything as ZIP');
    $component->assertDontSeeText('Dev Mode is off.');

    Livewire::actingAs(hdlUser(true, 'hdl-export-on'))->test(HelpDataLocations::class)
        ->assertSeeText('Export everything as ZIP');
});

it('offers no disabled export stub', function (): void {
    $component = Livewire::actingAs(hdlUser(false, 'hdl-nostub'))->test(HelpDataLocations::class);

    $component->assertDontSee('aria-disabled="true"', escape: false);
    $component->assertDontSeeText('Export action will ship with');
});

it('names every path in the deletion procedure, journal files included', function (): void {
    $user = hdlUser(false, 'hdl-delete');
    $locations = UserDataLocations::all();
    $databaseFiles = UserDataLocations::databaseFiles();

    expect($locations)->toHaveCount(7)
        ->and($databaseFiles)->toHaveCount(3);

    $component = Livewire::actingAs($user)->test(HelpDataLocations::class);

    $component->assertSeeText('Deleting your data');
    $component->assertSeeText('To remove every trace, delete each of these:');

    foreach ($locations as $path) {
        $component->assertSee($path);
    }

    $component->assertSeeText(basename($databaseFiles[1]));
    $component->assertSeeText(basename($databaseFiles[2]));

    // assertSeeText escapes the needle by default, turning the apostrophe into
    // `&#039;`, while the stripped-tags haystack still holds a raw `'` — hence
    // `escape: false`.
    $component->assertSeeText("There's no telemetry to opt out of and no remote account to close.", escape: false);
});

it('tells the reader plainly that uninstalling leaves the data behind', function (): void {
    $user = hdlUser(false, 'hdl-uninstall');

    Livewire::actingAs($user)->test(HelpDataLocations::class)
        ->assertSeeText('Uninstalling Beatrax does not delete your data.');
});

it('resolves paths only through the authenticated user (cross-user safety)', function (): void {
    // UserDataLocations is per-app, not per-user-input, so nothing userB
    // supplies can influence what userA's render shows.
    $userA = hdlUser(false, 'hdl-cross-a');
    $userB = hdlUser(true, 'hdl-cross-b');

    $component = Livewire::actingAs($userA)->test(HelpDataLocations::class);

    $component->assertSee(UserDataLocations::all()[UserDataLocations::DATABASE]);

    expect($userB->is_developer)->toBeTrue();
});
