<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Pipeline\PreviewKeys;
use Modules\Ledger\Models\ImportRun;

function undecodablePreviewReader(): User
{
    return User::query()->create([
        'username' => 'undecodable-preview-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function undecodablePreviewRun(User $user): ImportRun
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/undecodable.csv',
        'sha256' => str_pad(bin2hex(random_bytes(8)), 64, 'a'),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    return $run;
}

// PreviewCache::head() throws rather than reading a malformed payload as a
// miss, deliberately, so a cache regression stays distinguishable from a
// routine eviction. Nothing above it turned that into an answer: the wizard
// let it out and the reader got a crash page mid-import.
it('says the preview cannot be read rather than answering a server fault', function (): void {
    $user = undecodablePreviewReader();
    $run = undecodablePreviewRun($user);

    /** @var Repository $cache */
    $cache = app(Repository::class);
    $cache->put(PreviewKeys::head((int) $run->id), 'not-an-array', 600);

    $this->actingAs($user)
        ->get('/imports/'.$run->id.'/preview')
        ->assertOk()
        ->assertSee(Lang::get('import::preview.unreadable_html'), false);
});

// The two answers must stay apart. An evicted entry IS expired, and telling
// the reader of a malformed one that it expired names the one cause the
// throw has already ruled out.
it('does not call an unreadable preview an expired one', function (): void {
    $user = undecodablePreviewReader();
    $run = undecodablePreviewRun($user);

    /** @var Repository $cache */
    $cache = app(Repository::class);
    $cache->put(PreviewKeys::head((int) $run->id), 'not-an-array', 600);

    $this->actingAs($user)
        ->get('/imports/'.$run->id.'/preview')
        ->assertDontSee(Lang::get('import::preview.expired_html'), false);
});

it('still calls a preview that was simply evicted an expired one', function (): void {
    $user = undecodablePreviewReader();
    $run = undecodablePreviewRun($user);

    $this->actingAs($user)
        ->get('/imports/'.$run->id.'/preview')
        ->assertOk()
        ->assertSee(Lang::get('import::preview.expired_html'), false)
        ->assertDontSee(Lang::get('import::preview.unreadable_html'), false);
});
