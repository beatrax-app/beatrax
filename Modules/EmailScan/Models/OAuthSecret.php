<?php

declare(strict_types=1);

namespace Modules\EmailScan\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\EmailScan\Database\Factories\OAuthSecretFactory;

/**
 * Eloquent model for the oauth_secrets table — per-user OAuth client
 * credentials and token blob for one connected email provider.
 *
 * A user holds at most one credential row per provider ('gmail' or
 * 'microsoft'), guarded by the table's unique (user_id, provider) index
 * and its provider enum trigger pair. The `client_secret` and
 * `tokens_blob` columns are AES-256-CBC encrypted at rest via Laravel's
 * `encrypted` cast keyed on APP_KEY; plaintext exists only inside this
 * model's attribute layer.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $provider
 * @property string $client_id
 * @property string $client_secret encrypted at rest
 * @property string $redirect_uri
 * @property string|null $tokens_blob encrypted at rest
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class OAuthSecret extends Model
{
    use BelongsToUser;

    /** @use HasFactory<OAuthSecretFactory> */
    use HasFactory;

    /** @var string|null */
    protected $table = 'oauth_secrets';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'provider',
        'client_id',
        'client_secret',
        'redirect_uri',
        'tokens_blob',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'tokens_blob' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return OAuthSecretFactory::new();
    }
}
