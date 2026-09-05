<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

final class BelongsToUserSampleModel extends Model
{
    use BelongsToUser;

    /** @var string */
    protected $table = 'belongs_to_user_sample_models';

    /** @var list<string> */
    protected $fillable = ['name'];
}
