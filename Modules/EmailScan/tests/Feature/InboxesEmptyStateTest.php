<?php

declare(strict_types=1);

use Modules\Core\Models\User;

function iesUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

it('renders the empty-state hero when zero inboxes are connected', function (): void {
    $user = iesUser('empty@example.com');
    $this->actingAs($user);

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    $response->assertSee('Inboxes', false);
    $response->assertSee('Connect Gmail and Microsoft 365 inboxes so Beatrax can scan them for receipts.', false);
    $response->assertSee('Connect your email', false);
    $response->assertSee('Import receipts from PayPal, ICS Cards, Google Play, and other merchants', false);
    $response->assertSee("openWizard('gmail')", false);
    $response->assertSee("openWizard('microsoft')", false);
    $response->assertSee('Beatrax only reads messages.', false);

    // The add-inbox cards appear only once at least one inbox is connected.
    $response->assertDontSee('Add another inbox', false);
});
