<?php

declare(strict_types=1);

use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->testUser = User::create([
        'email' => 'test@127.0.0.1',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
});

it('renders the calm login page on GET /login', function (): void {
    $response = $this->get('/login');
    $response->assertOk();
    $response->assertSee('Sign in', false);
    $response->assertDontSee('Register');
    $response->assertDontSee('Forgot your password');
});

it('authenticates valid credentials and redirects to /', function (): void {
    $response = $this->post('/login', [
        'email' => 'test@127.0.0.1',
        'password' => 'opensesame',
        'remember' => true,
    ]);

    $response->assertRedirect('/');
    expect(auth()->check())->toBeTrue();
});

it('rejects wrong password with the canonical error message', function (): void {
    $response = $this->from('/login')->post('/login', [
        'email' => 'test@127.0.0.1',
        'password' => 'WRONG',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors();

    $followed = $this->get('/login');
    $followed->assertSee('Email or password is incorrect.', false);
});

it('every authenticated response carries no-store Cache-Control', function (): void {
    $this->actingAs($this->testUser);
    $response = $this->get('/login');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
