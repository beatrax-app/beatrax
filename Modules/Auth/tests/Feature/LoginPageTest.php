<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Core\Models\User;

beforeEach(function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $this->password = 'correct-horse-battery';

    $this->user = User::query()->create([
        'username' => 'alice',
        'password' => $hasher->make($this->password),
        'period_start_day' => 1,
    ]);
});

it('renders the sign-in page with the correct copy', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSeeText('Sign in')
        ->assertSeeText('Sign in to continue.')
        ->assertSeeText('Stay signed in on this device')
        ->assertSeeText('Lost your password? Use a recovery code.');
});

it('keeps the login page publicly accessible', function (): void {
    $this->get('/login')->assertOk();
});

it('signs in with valid credentials', function (): void {
    Livewire::test(LoginPage::class)
        ->set('username', 'alice')
        ->set('password', $this->password)
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->user);
});

it('shows the generic error on a wrong password', function (): void {
    Livewire::test(LoginPage::class)
        ->set('username', 'alice')
        ->set('password', 'wrong-password')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Username or password is incorrect.');

    $this->assertGuest();
});

it('shows the same generic error for an unknown username', function (): void {
    Livewire::test(LoginPage::class)
        ->set('username', 'nobody')
        ->set('password', 'whatever-password')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Username or password is incorrect.');

    $this->assertGuest();
});

it('resolves the username case-insensitively', function (): void {
    Livewire::test(LoginPage::class)
        ->set('username', 'Alice')
        ->set('password', $this->password)
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->user);
});

it('sets the remember cookie when remember-me is checked', function (): void {
    $response = Livewire::test(LoginPage::class)
        ->set('username', 'alice')
        ->set('password', $this->password)
        ->set('rememberMe', true)
        ->call('submit')
        ->assertRedirect(route('dashboard'))
        ->effects['returns'] ?? null;

    $cookieNames = array_map(
        static fn ($cookie): string => $cookie->getName(),
        app('cookie')->getQueuedCookies(),
    );

    $hasRememberCookie = false;
    foreach ($cookieNames as $name) {
        if (str_starts_with($name, 'remember_web_')) {
            $hasRememberCookie = true;
        }
    }

    expect($hasRememberCookie)->toBeTrue();
});

it('signs the user out via the logout route', function (): void {
    $this->actingAs($this->user)
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
});
