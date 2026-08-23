<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

// The envelope table's min-content width exceeds the content column in the
// desktop shell's default 1100px window, so without its own scroll container
// it widens the document and pushes "Notify at" off the page.
// @link ../../../../.docs/conventions/arch-invariants.md

beforeEach(function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'budgets-overflow',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->user = $user;

    Category::create([
        'user_id' => null,
        'name' => 'Car maintenance',
        'slug' => 'overflow-car-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

it('keeps the envelope table inside a horizontal scroll container', function (): void {
    $this->actingAs($this->user);

    $html = Livewire::test(BudgetsPage::class)->html();

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);
    $tables = $xpath->query('//table[contains(@class, "md:table")]');

    expect($tables)->not->toBeNull();
    expect($tables->length)->toBeGreaterThan(0, 'the desktop envelope table is not rendered at all');

    foreach ($tables as $table) {
        $scrolls = false;
        for ($node = $table->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (str_contains($node->getAttribute('class'), 'overflow-x-auto')) {
                $scrolls = true;
                break;
            }
        }

        expect($scrolls)->toBeTrue('the envelope table has no overflow-x-auto ancestor, so the page scrolls sideways instead');
    }
});
