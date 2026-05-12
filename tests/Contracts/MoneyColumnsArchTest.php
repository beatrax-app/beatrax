<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\DatabaseManager;

it('transactions table has both native and settled money columns (MC-01)', function (): void {
    $this->app->make(ConsoleKernel::class)->call('migrate:fresh');

    $connection = $this->app->make(DatabaseManager::class)->connection();
    $columns = collect($connection->getSchemaBuilder()->getColumns('transactions'))->pluck('name');

    expect($columns)->toContain('amount_minor');
    expect($columns)->toContain('currency');
    expect($columns)->toContain('settled_amount_minor');
    expect($columns)->toContain('settled_currency');
});
