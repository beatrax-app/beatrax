<?php

declare(strict_types=1);

/**
 * @link ../../.docs/architecture/reads-bounded-by-the-user.md#the-other-axis-a-read-bounded-by-how-much-the-sender-sent
 */

// The whole backend runs on the reader's phone, on one `php -S` process inside
// a 128 MB ceiling. A read whose length a mail provider or a dropped-in file
// chooses is therefore a fatal waiting to be sent one: an exhausted heap is
// E_ERROR, so there is no exception to catch, no log line and no retry.
//
// Every message-shaped read on these paths goes through
// Modules\Core\Public\Support\BoundedRead, which settles the size before the
// bytes are materialised. The point of pinning it is that the last round had
// the check on one door and not on its identical sibling, and nothing said so.

/** @return array<string, string> repo-relative path => contents */
function boundedMessageReadSources(): array
{
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    // The live provider clients and the jobs that consume what they store.
    // The Fake* clients alongside them replay .eml fixtures out of this repo,
    // which is the one case where the length is not somebody else's to choose.
    $patterns = [
        'Modules/EmailScan/Internal/Clients/G*ApiClient.php',
        'Modules/EmailScan/Internal/Jobs/*.php',
        'Modules/Receipts/Internal/Jobs/*.php',
    ];

    $sources = [];
    foreach ($patterns as $pattern) {
        foreach (glob($repoRoot.'/'.$pattern) ?: [] as $path) {
            $sources[str_replace($repoRoot.'/', '', $path)] = (string) file_get_contents($path);
        }
    }

    return $sources;
}

/** @return list<string> every live provider client in the mailbox directory */
function boundedMessageProviderClients(): array
{
    $found = [];

    foreach (glob(base_path('Modules/EmailScan/Internal/Clients/*ApiClient.php')) ?: [] as $path) {
        $name = basename($path);

        // A Fake* client replays an .eml fixture out of this repo, which is the
        // one case where the length is not somebody else's to choose.
        if (! str_starts_with($name, 'Fake')) {
            $found[] = 'Modules/EmailScan/Internal/Clients/'.$name;
        }
    }

    sort($found);

    return $found;
}

it('does not allow a provider or drop-folder message to be materialised outside BoundedRead', function (): void {
    // Each pattern is a way a whole body reaches one PHP string with nothing
    // between the sender's length and the heap. A hit is not automatically a
    // defect — it is a place that has to say which ceiling it read under —
    // so a legitimate one goes on the list below with its reason above it.
    $unboundedReads = [
        '(string) $response->getBody()',
        '$response->getBody()->getContents()',
        'file_get_contents(',
        'stream_get_contents(',
        '$files->get(',
        '$this->files->get(',
    ];

    // Deliberately empty: no hit on these paths is currently a legitimate
    // unbounded read, and an entry here would name one with the reason it is
    // bounded by something real. Spelled out rather than absent so the failure
    // message below has a list to point at.
    $pinned = [];

    $found = [];
    foreach (boundedMessageReadSources() as $relative => $contents) {
        foreach ($unboundedReads as $pattern) {
            if (! str_contains($contents, $pattern)) {
                continue;
            }
            $hit = $relative.' :: '.$pattern;
            if (! in_array($hit, $pinned, true)) {
                $found[] = $hit;
            }
        }
    }
    sort($found);

    expect($found)->toBe(
        [],
        'A message body on the inbox-scan or drop-folder path is read whole without a ceiling. Route it '
        ."through Modules\\Core\\Public\\Support\\BoundedRead, or pin the line below with the reason:\n  "
        .implode("\n  ", $found),
    );
});

// The guard above is a text scan, and a text scan that stops matching reports
// silence as success. This holds it to a file it must always be able to see.
it('scans the client and job files the ceiling is meant to cover', function (): void {
    $sources = boundedMessageReadSources();

    expect(array_keys($sources))
        ->toContain('Modules/EmailScan/Internal/Clients/GraphApiClient.php')
        ->toContain('Modules/EmailScan/Internal/Clients/GmailApiClient.php')
        ->toContain('Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php')
        ->toContain('Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php')
        ->and($sources['Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php'])
        ->toContain('BoundedRead::file(');
});

// `G*ApiClient.php` is a hand-written narrowing of the clients directory, and a
// hand-written narrowing cannot see a provider added under another initial. The
// glob's answer is held to the directory's own contents instead.
it('reaches every live provider client the mailbox directory holds', function (): void {
    $clients = boundedMessageProviderClients();

    expect(count($clients))->toBeGreaterThan(
        1,
        'Almost no provider client was found, so the comparison below is about a directory nobody read.',
    );

    $missed = array_values(array_diff($clients, array_keys(boundedMessageReadSources())));

    expect($missed)->toBe([], implode("\n  ", [
        'These provider clients fetch a message body whose length a mail provider chooses, and the glob',
        'above does not reach them — so the ceiling rule is silent about the one path it exists for.',
        'Widen the pattern in boundedMessageReadSources() to name them:',
        ...$missed,
    ]));
});
