<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;

uses(RefreshDatabase::class);

// This page opened with its search toolbar and put its own title underneath.
// Measured at 375px and 411px on the phone's 17px root, with the page column at
// the same padding either way: "Budgets", "Cash book" and "Transaction" started
// their h1 at 99px and "Transactions" started its at 252.5px.
//
// The header has to be FIRST, not merely above the toolbar: the component root
// is `space-y-6`, which gives a margin-top to every child except the first, so
// a zero-height sibling ahead of it still costs the title 25.5px.

function transactionsListDocument(): DOMXPath
{
    $user = User::query()->create([
        'username' => 'leads-with-title-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);

    test()->actingAs($user);

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.Livewire::test(TransactionsList::class)->html());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('opens the page with its own header and not with the search toolbar', function (): void {
    $xpath = transactionsListDocument();

    $root = $xpath->query('//body/*[1]')->item(0);

    expect($root)->not->toBeNull();

    $firstElement = $xpath->query('*[1]', $root)->item(0);

    expect($firstElement?->nodeName)->toBe('header');
});

it('draws the search toolbar below the title, not above it', function (): void {
    $xpath = transactionsListDocument();

    $heading = $xpath->query('//h1')->item(0);
    $toolbar = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " srch-toolbar ")]')->item(0);

    expect($heading)->not->toBeNull()
        ->and($toolbar)->not->toBeNull()
        ->and($heading->compareDocumentPosition($toolbar) & DOMNode::DOCUMENT_POSITION_FOLLOWING)
        ->toBeGreaterThan(0);
});

it('puts the phone filter button on the search input line and outside the sheet', function (): void {
    $xpath = transactionsListDocument();

    $button = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " srch-filters-btn ")]')->item(0);

    expect($button)->not->toBeNull();

    $inRow = $xpath->query(
        'ancestor::*[contains(concat(" ", normalize-space(@class), " "), " srch-input-row ")]',
        $button,
    );

    // Everything passed to x-core::bottom-sheet renders inside a panel that is
    // display:none until it opens, so a button that opens the sheet from inside
    // it is a button nobody can reach.
    $inSheet = $xpath->query(
        'ancestor::*[contains(concat(" ", normalize-space(@class), " "), " bottom-sheet ")]',
        $button,
    );

    expect($inRow->length)->toBe(1)
        ->and($inSheet->length)->toBe(0);

    $field = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " srch-input-wrap ")]')->item(0);

    expect($field)->not->toBeNull()
        ->and($xpath->query(
            'ancestor::*[contains(concat(" ", normalize-space(@class), " "), " srch-input-row ")]',
            $field,
        )->length)->toBe(1);
});
