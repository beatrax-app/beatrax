<?php

declare(strict_types=1);

// The guarantee was pinned by a test that skipped itself, claiming there was no
// way to drive unauthenticated state from Pest. There is; what there was not is
// a file whose beforeEach does not sign someone in first — DashboardTest calls
// actingAs() for every case, so a guest assertion there answers about a reader.

it('sends a signed-out visitor to the login screen rather than the dashboard', function (): void {
    $this->get('/')->assertRedirect('/login');
});

it('sends a signed-out visitor away from settings too', function (): void {
    $this->get('/settings')->assertRedirect('/login');
});
