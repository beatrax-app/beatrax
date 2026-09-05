<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tests\Support\BelongsToUserSampleModel;

it('adds user_id to fillable on init', function (): void {
    $model = new BelongsToUserSampleModel(['name' => 'x']);
    expect($model->getFillable())->toContain('user_id');
    expect($model->getFillable())->toContain('name');
});

it('exposes a user() belongs-to relationship', function (): void {
    $relation = (new BelongsToUserSampleModel)->user();
    expect($relation)->toBeInstanceOf(BelongsTo::class);
});
