<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;

// A four-character prefix is what survives the case endings 26 languages put on
// the same noun, and Turkish's dotted I lowercases to i plus a combining dot
// that is not a letter of its own.
/** @return list<string> */
function deleteQuestionStems(string $sentence): array
{
    $lowered = str_replace("\u{0307}", '', mb_strtolower($sentence));
    $words = preg_split('~[^\p{L}\p{N}-]+~u', $lowered, -1, PREG_SPLIT_NO_EMPTY);

    $stems = [];
    foreach ($words === false ? [] : $words as $word) {
        if (mb_strlen($word) >= 4) {
            $stems[mb_substr($word, 0, 4)] = true;
        }
    }

    return array_keys($stems);
}

/** @return list<string> the delete question, and the two sentences it is read against */
function deleteQuestionCopy(string $locale): array
{
    return [
        Lang::get('ledger::detail.delete.confirm_prompt', [], $locale),
        Lang::get('ledger::detail.delete.heading', [], $locale),
        Lang::get('ledger::detail.delete.help', [], $locale),
        Lang::get('ledger::detail.unreconcile.help', [], $locale),
    ];
}

beforeEach(function (): void {
    $this->user = User::create(['username' => 'delete-question-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-delete-question-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000021',
        'default_currency' => 'EUR',
    ]);

    $this->run = $this->makeImportRun($this->user);
});

// "Are you sure?" shipped in all 26. A question that only asks for a mood names
// no verb, no row and nothing lost, so the reader answers it from what they
// remember rather than from what it says.
it('asks the delete question in words that name what the delete takes', function (): void {
    expect(Locale::cases())->toHaveCount(26);

    $thin = [];

    foreach (Locale::cases() as $locale) {
        [$question, $heading, $help, $inventory] = deleteQuestionCopy($locale->value);

        expect($question)->not->toContain('ledger::');

        $asked = deleteQuestionStems($question);
        $act = deleteQuestionStems($heading);
        $lost = array_diff(
            deleteQuestionStems($inventory),
            $act,
            deleteQuestionStems($help),
        );

        if (array_intersect($asked, $act) === []) {
            $thin[] = $locale->value.': "'.$question.'" names neither the verb nor the row it acts on';

            continue;
        }

        $named = array_intersect($asked, $lost);

        if (count($named) < 2) {
            $thin[] = $locale->value.': "'.$question.'" names '.count($named).' of the things that go with the row';
        }
    }

    expect($thin)->toBe([], "The delete question says what happens and what is lost, in every locale, in that locale's own words for them — .docs/conventions/which-actions-ask-before-they-act.md. These asked for something else:\n  ".implode("\n  ", $thin));
});

it('draws that question on the page the delete is asked from', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared']);

    $html = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])->html();

    expect($html)->toContain(Lang::get('ledger::detail.delete.confirm_prompt', [], 'en'));
});

// The coarse-pointer floor writes min-width: 44px over min-width: auto, so a
// shrinkable answer in a full row lands at exactly 44px with its label broken
// one word per line. The question is longer than the mood it replaced, and this
// strip is hand-rolled rather than x-core::confirm-strip, which took this fix.
it('lets the delete answers wrap rather than squeeze to the touch floor', function (): void {
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared']);

    $html = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])->html();

    $at = strpos($html, 'data-testid="delete-section"');

    expect($at)->not->toBeFalse('the page never drew the delete section');

    $section = substr($html, (int) $at, (int) strpos($html, '</section>', (int) $at) - (int) $at);

    expect($section)->toContain('flex flex-wrap items-center')
        ->and(substr_count($section, 'shrink-0'))->toBeGreaterThanOrEqual(2);
});
