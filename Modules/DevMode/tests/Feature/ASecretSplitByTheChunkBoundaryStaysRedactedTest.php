<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;

// The tailer returns a fixed 64 KiB window and redaction is a pattern match,
// so a secret straddling the boundary matched in neither half and both halves
// reached the browser verbatim.

function boundaryLogUser(): User
{
    return User::query()->create([
        'username' => 'chunk-boundary-dev',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

it('never emits either half of a secret that straddles the read window', function (): void {
    $user = boundaryLogUser();

    $prefix = '';
    while (strlen($prefix) < 65_520) {
        $prefix .= "filler line\n";
    }
    $secretLine = "Authorization: Bearer SUPERSECRETTOKENVALUE0123456789\n";

    expect(strlen($prefix))->toBeLessThan(65_536);
    expect(strlen($prefix) + strlen($secretLine))->toBeGreaterThan(65_536);

    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-storage-'.bin2hex(random_bytes(6));
    putenv('NATIVEPHP_STORAGE_PATH='.$sandbox);
    $path = UserDataPathService::dailyLogFile();
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $prefix.$secretLine);

    $first = $this->actingAs($user)->getJson('/dev/logs/poll?since=0');
    $first->assertOk();
    $firstChunk = (string) $first->json('chunk');
    $offset = (int) $first->json('newOffset');

    expect($firstChunk)->not->toContain('SUPERSECRET');
    expect($firstChunk)->not->toContain('Authorizat');
    expect($offset)->toBe(strlen($prefix));

    $second = $this->actingAs($user)->getJson('/dev/logs/poll?since='.$offset);
    $second->assertOk();
    $secondChunk = (string) $second->json('chunk');

    expect($secondChunk)->toContain('Authorization: Bearer [REDACTED]');
    expect($secondChunk)->not->toContain('SUPERSECRET');
});

it('redacts a credential carried as a header array rather than a formatted line', function (): void {
    $processor = new Modules\DevMode\Internal\Logging\RedactSecretsProcessor(null);

    $scrubbed = (new ReflectionMethod($processor, 'scrubArray'))->invoke($processor, [
        'headers' => ['Authorization' => 'Bearer a0S1dF2gH3jK4lZ5xC6vB7nM8qW9'],
        'query' => ['access_token' => 'ya29.a0AfH6SMBx-not-a-real-token'],
        'note' => 'ImportRun 42 finished',
    ]);

    expect($scrubbed['headers']['Authorization'])->toBe('[REDACTED]');
    expect($scrubbed['query']['access_token'])->toBe('[REDACTED]');
    expect($scrubbed['note'])->toBe('ImportRun 42 finished');
});
