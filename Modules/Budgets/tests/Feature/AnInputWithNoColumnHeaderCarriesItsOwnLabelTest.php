<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

// Under 640px the table becomes a card and its column headers are not drawn,
// so a field that leaned on one is left with an aria-label and nothing on the
// screen. On the iPhone the box that assigns the money was the bare one and
// the less consequential notify threshold beside it was the labelled one.

uses(RefreshDatabase::class);

/**
 * @return list<DOMElement>
 */
function budgetInputsOutsideTheTable(string $html): array
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();

    $inputs = [];
    foreach ((new DOMXPath($dom))->query('//input[@type="text"]') ?: [] as $input) {
        if (! $input instanceof DOMElement) {
            continue;
        }
        if (budgetAncestorTag($input, 'table') === null) {
            $inputs[] = $input;
        }
    }

    return $inputs;
}

function budgetAncestorTag(DOMElement $element, string $tag): ?DOMElement
{
    for ($node = $element->parentNode; $node !== null; $node = $node->parentNode) {
        if ($node instanceof DOMElement && $node->tagName === $tag) {
            return $node;
        }
    }

    return null;
}

// A label the reader cannot see is the defect, not the fix, so `sr-only` text
// and anything `aria-hidden` are stripped before the label is judged. The
// `sm:not-sr-only` pattern is hidden at exactly the width this card exists at.
function budgetVisibleLabelText(DOMElement $input): string
{
    $label = budgetAncestorTag($input, 'label');

    if ($label === null) {
        $id = $input->getAttribute('id');
        $owner = $input->ownerDocument;
        if ($id === '' || $owner === null) {
            return '';
        }
        foreach ((new DOMXPath($owner))->query(sprintf('//label[@for="%s"]', $id)) ?: [] as $candidate) {
            if ($candidate instanceof DOMElement) {
                $label = $candidate;
                break;
            }
        }
    }

    return $label === null ? '' : budgetVisibleText($label);
}

function budgetVisibleText(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return $node->wholeText;
    }

    if (! $node instanceof DOMElement) {
        return '';
    }

    $classes = $node->getAttribute('class');
    $hiddenAtCardWidth = str_contains($classes, 'sr-only') && ! str_contains($classes, 'sm:not-sr-only');
    if ($hiddenAtCardWidth || $node->getAttribute('aria-hidden') === 'true') {
        return '';
    }

    $text = '';
    foreach ($node->childNodes as $child) {
        $text .= budgetVisibleText($child);
    }

    return $text;
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'own-label-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'own-label-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    app(EnvelopeWriter::class)->setAssigned(
        $this->user,
        $this->groceries->id,
        app(PeriodQuery::class)->current()->start,
        5000,
    );
});

it('gives every phone-card field a label the reader can read', function (): void {
    $inputs = budgetInputsOutsideTheTable(Livewire::test(BudgetsPage::class)->html());

    expect($inputs)->not->toBe([], 'The phone card renders no text field, so this proves nothing.');

    $bare = [];
    foreach ($inputs as $input) {
        if (trim(budgetVisibleLabelText($input)) === '') {
            $bare[] = $input->getAttribute('wire:model');
        }
    }

    expect($bare)->toBe([]);
});

// The card holds the assign box and the notify threshold side by side. The one
// that moves the money is the one that has to be at least as legible as the
// one that sets a warning percentage.
it('labels the box that assigns the money, not only the one beside it', function (): void {
    $inputs = budgetInputsOutsideTheTable(Livewire::test(BudgetsPage::class)->html());

    $labels = [];
    foreach ($inputs as $input) {
        $labels[$input->getAttribute('wire:model')] = trim(budgetVisibleLabelText($input));
    }

    $assigned = 'assignedInputs.'.$this->groceries->id;
    $threshold = 'thresholdInputs.'.$this->groceries->id;

    expect($labels)->toHaveKeys([$assigned, $threshold])
        ->and($labels[$assigned])->toContain(trans('budgets::messages.table.assigned'))
        ->and($labels[$threshold])->not->toBe('');
});
