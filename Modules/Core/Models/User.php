<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Single-user authentication entity.
 *
 * @property int $id
 * @property string $email
 * @property string $password
 * @property int $period_start_day
 * @property string $default_currency_view
 * @property bool|null $auto_import_drop_folder
 * @property string $receipt_conflict_resolution
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'password',
        'period_start_day',
        'default_currency_view',
        'auto_import_drop_folder',
        'receipt_conflict_resolution',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'period_start_day' => 'integer',
            'default_currency_view' => 'string',
            'auto_import_drop_folder' => 'boolean',
        ];
    }
}
