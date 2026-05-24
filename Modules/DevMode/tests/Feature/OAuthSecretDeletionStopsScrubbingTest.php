<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Logging\PushRedactProcessor;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

/*
 * Phase 16-05 Task 1 Test 6 (I-4 regression guard) — documented
 * limitation: once an OAuthSecret row is DELETED, the cache busts and
 * the deleted string disappears from the scrub set. A subsequent log
 * line containing the now-revoked string is NOT scrubbed.
 *
 * This is INTENTIONAL behavior — a revoked + removed token is no
 * longer sensitive in this app's threat model (the secret is dead;
 * the audit trail cares about new lines, not old). The test exists
 * so a future change that silently keeps scrubbing deleted values
 * (e.g. a tombstone table) is surfaced at PR time.
 *
 * The behavior is also surfaced in the plan's SUMMARY known-limitations
 * section.
 */

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

it('STOPS scrubbing a string after the OAuthSecret row is deleted (documented limitation, I-4)', function (): void {
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

    // Delete the secret. The deleted observer fires; the next call
    // to compiledPattern() lazy-rebuilds from the now-empty table.
    $secret->delete();

    expect($scrubSet->all())->not->toContain('REVOKED_SECRET');

    // End-to-end: log the now-revoked string — it is NOT scrubbed
    // because the row no longer exists in the live table. This is
    // the acceptable behavior described in the plan's <interfaces>
    // block and the documented limitation in the SUMMARY.
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

    // The intentional limitation: deleted strings are not scrubbed.
    expect($contents)->toContain('REVOKED_SECRET');
});
