<?php

declare(strict_types=1);

it('reports installed versions and exits 0 on a healthy environment', function (): void {
    $this->artisan('diederik:doctor')
        ->expectsOutputToContain('PHP')
        ->expectsOutputToContain('Composer')
        ->expectsOutputToContain('SQLite')
        ->assertSuccessful();
});
