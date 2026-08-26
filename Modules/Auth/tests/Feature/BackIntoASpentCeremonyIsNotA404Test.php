<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Core\Models\User;

// Measured on the Samsung. Signup goes / → /signup → /recovery-codes →
// /setup-wizard, and the wizard's nine steps share one URL, so the newest
// history entry behind the wizard is the codes page. Pressing the system back
// button on the first step of first-run setup showed "404 · This page does not
// exist. The link may be old, or the page may have been renamed." — on the
// second screen of owning the app.
//
// The codes must still never be shown twice. A reader who has finished the
// ceremony is sent on rather than into an error; a guest still meets the 404,
// because for them the page really is nothing.

it('sends a reader who already finished the ceremony onward', function (): void {
    $user = User::query()->create([
        'username' => 'spent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->actingAs($user);
    app(Session::class)->forget(RecoveryCodesDisplay::SESSION_KEY);

    $this->get('/recovery-codes')
        ->assertRedirect(route('setup'));
});

it('still refuses a guest, for whom the page is nothing', function (): void {
    app(Session::class)->forget(RecoveryCodesDisplay::SESSION_KEY);

    $response = $this->get('/recovery-codes');

    expect($response->getStatusCode())->not->toBe(200);
});

it('never re-shows the codes themselves', function (): void {
    $user = User::query()->create([
        'username' => 'spent2-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->actingAs($user);
    app(Session::class)->forget(RecoveryCodesDisplay::SESSION_KEY);

    $html = (string) $this->followingRedirects()->get('/recovery-codes')->getContent();

    expect($html)->not->toMatch('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/');
});
