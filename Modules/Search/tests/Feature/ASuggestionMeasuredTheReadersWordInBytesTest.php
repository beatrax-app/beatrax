<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Search\Internal\Services\DidYouMeanSuggester;

// The suggester measured the reader's word in bytes and folded its case in
// ASCII. Both are free for English and wrong for the other twenty-five
// locales: an accented letter counted twice against a threshold of two, and
// an upper-case one never folded, so it was offered back still upper-case.

it('offers a fully folded word for a statement that shouts an accented name', function (): void {
    $user = User::findOrFail($this->searchTestUser('bytes-fold-'.bin2hex(random_bytes(3))));

    // Statements routinely arrive upper-case, which is the only reason a
    // half-folded word could ever be shown to a reader.
    $this->searchTestTransaction($user->id, [
        'counterparty_name' => 'CAFE ZURICH',
        'counterparty_normalized' => 'cafe zurich',
        'description' => 'coffee',
    ]);
    $this->searchTestTransaction($user->id, [
        'counterparty_name' => 'CAFÉ ZÜRICH',
        'counterparty_normalized' => 'café zürich',
        'description' => 'coffee',
    ]);

    expect(app(DidYouMeanSuggester::class)->suggest($user, 'zurich'))->toBe('zürich');
});

it('counts an accented letter once against the distance threshold', function (): void {
    $user = User::findOrFail($this->searchTestUser('bytes-dist-'.bin2hex(random_bytes(3))));

    // Stored already folded, so nothing here turns on the case fix: two
    // accents are two edits, and the threshold the class documents is two.
    $this->searchTestTransaction($user->id, [
        'counterparty_name' => 'épicerie générale',
        'counterparty_normalized' => 'épicerie générale',
        'description' => 'groceries',
    ]);

    expect(app(DidYouMeanSuggester::class)->suggest($user, 'generale'))->toBe('générale');
});

it('reads the four-character floor as characters, not as bytes', function (): void {
    $user = User::findOrFail($this->searchTestUser('bytes-floor-'.bin2hex(random_bytes(3))));

    $this->searchTestTransaction($user->id, [
        'counterparty_name' => 'ZOO Antwerpen',
        'counterparty_normalized' => 'zoo antwerpen',
        'description' => 'a day out',
    ]);

    // Three characters, four bytes. A three-letter ASCII query is refused
    // here, and this one has to be refused for the same reason.
    expect(app(DidYouMeanSuggester::class)->suggest($user, 'Zoë'))->toBeNull();
});
