<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Logging\PushRedactProcessor;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

// Deleting a row drops its string from the scrub set, so later log lines
// carrying it go unscrubbed. Accepted: a revoked and removed token is dead.
// The test is here so a tombstone table added later has to argue its case.
function deletionUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

it('STOPS scrubbing a string after the OAuthSecret row is deleted (documented limitation)', function (): void {
    $user = deletionUser('deletion-fixture');
    $this->actingAs($user);

    /** @var OAuthSecret $secret */
    $secret = OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'cid',
        'client_secret' => 'REVOKED_SECRET',
        'redirect_uri' => 'https://example.test/cb',
        'tokens_blob' => null,
    ]);

    /** @var OAuthScrubSet $scrubSet */
    $scrubSet = app(OAuthScrubSet::class);
    expect($scrubSet->all())->toContain('REVOKED_SECRET');

    $secret->delete();

    expect($scrubSet->all())->not->toContain('REVOKED_SECRET');

    $tmpPath = tempnam(sys_get_temp_dir(), 'deletion-').'.log';
    /** @var LogManager $manager */
    $manager = app(LogManager::class);
    $channel = $manager->build([
        'driver' => 'single',
        'path' => $tmpPath,
        'level' => 'debug',
    ]);
    (new PushRedactProcessor)($channel);

    $channel->info('Mentioning REVOKED_SECRET in log');

    foreach ($channel->getLogger()->getHandlers() as $handler) {
        if (method_exists($handler, 'close')) {
            $handler->close();
        }
    }

    $contents = (string) file_get_contents($tmpPath);
    @unlink($tmpPath);

    // Asserting the leak on purpose — see the note at the top of the file.
    expect($contents)->toContain('REVOKED_SECRET');
});
