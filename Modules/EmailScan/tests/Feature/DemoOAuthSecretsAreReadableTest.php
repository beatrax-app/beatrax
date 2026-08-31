<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Database\Seeders\Demo\DemoEmailScanSeeder;
use Modules\EmailScan\Models\Inbox;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;

uses(RefreshDatabase::class);

// tokens_blob is a map keyed by inbox id. A demo row holding a flat token pair
// decodes to no inbox at all, every scan job the demo install dispatches then
// throws InboxNotConfiguredException, and the queue retries each one to
// exhaustion — hundreds of failed jobs off one seeder line.
it('seeds oauth credentials the repository can read back for every demo inbox', function (): void {
    $user = User::query()->create([
        'username' => 'demo-1',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var DemoEmailScanSeeder $seeder */
    $seeder = $this->app->make(DemoEmailScanSeeder::class);
    $seeder->run(['demo-1' => $user]);

    $this->actingAs($user);

    /** @var OAuthSecretsRepository $secrets */
    $secrets = $this->app->make(OAuthSecretsRepository::class);

    $inboxes = Inbox::query()->where('user_id', $user->id)->get();
    expect($inboxes)->toHaveCount(2);

    foreach ($inboxes as $inbox) {
        $credentials = $secrets->loadInbox($inbox->id);

        expect($credentials)->not->toBeNull("No credentials readable for inbox {$inbox->id}");
        expect($credentials->provider)->toBe($inbox->provider);
        expect($credentials->refreshToken)->not->toBe('');
        expect($credentials->accessToken)->not->toBeNull();
        // Not already expired, or the very first scan tries to refresh a demo
        // token against a provider that will never answer.
        expect($credentials->expiresAt)->not->toBeNull();
    }

    expect($secrets->loadProviderClient(MailProvider::Gmail->value))->not->toBeNull();
    expect($secrets->loadProviderClient(MailProvider::Microsoft->value))->not->toBeNull();
});
