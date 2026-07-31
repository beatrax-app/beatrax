<?php

declare(strict_types=1);

namespace Modules\EmailScan\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\EmailScan\Models\OAuthSecret;
use Modules\EmailScan\Public\Enums\MailProvider;

/**
 * @extends Factory<OAuthSecret>
 */
final class OAuthSecretFactory extends Factory
{
    /** @var class-string<OAuthSecret> */
    protected $model = OAuthSecret::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $providers = [MailProvider::Gmail->value, MailProvider::Microsoft->value];
        $provider = $providers[array_rand($providers)];

        // client_secret and tokens_blob are plaintext here; OAuthSecret's
        // encrypted cast performs the actual encryption on write, so
        // fixtures never need to fabricate ciphertext.
        return [
            'user_id' => fn (): int => User::query()->create([
                'username' => 'oauth-'.Str::lower(Str::random(12)),
                'password' => 'fixture-password',
                'period_start_day' => 1,
            ])->id,
            'provider' => $provider,
            'client_id' => Str::random(24),
            'client_secret' => Str::random(32),
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/callback/'.$provider,
            'tokens_blob' => null,
        ];
    }
}
