<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/one-dialect-for-identifiers.md
 */

// One dialect per surface. A name the machine resolves is American; English a
// reader is shown, and the prose explaining it, stays British.
//
// The tree held both at once. `normalise` sat beside `normalize`, and
// `BiometricEnrolmentOutcome` beside `ColdStartEnroller` in one directory — so
// a caller could not tell which spelling a symbol answered to without opening
// it. The module is named `Categorization`, which already commits every
// namespace, provider and doc reference in it to the American spelling, so the
// cheap half of the split was already decided.
//
// The spell-checker cannot hold this line on its own. `typos` reads a file as a
// stream of words with no idea which are code, so turning its `en-us` locale on
// reports the British comments and the British copy too — 1,638 of them, every
// one correct. The convention page carries that measurement; this rule is the
// half of it that can tell an identifier from a sentence.

// British spellings with no American reading, keyed to what replaces them.
// The irregular half of the convention: a rule cannot derive `labelled` from
// `label` without also deriving `controller` from `control`, so these are named
// rather than matched. The two productive families are nets instead, below.
//
// `cancelled` is deliberately absent. It is the name of a column — the schema
// holds `cancelled_at` — and a column is renamed by a migration, which is not
// worth writing for a spelling. Every name around it matches the column on
// purpose. `cancellation` doubles its l in both dialects and was never at stake.
const BRITISH_IDENTIFIER_WORDS = [
    'analyse' => 'analyze', 'analysed' => 'analyzed', 'analyser' => 'analyzer', 'analyses' => 'analyzes', 'analysing' => 'analyzing',
    'artefact' => 'artifact', 'artefacts' => 'artifacts',
    'behaviour' => 'behavior', 'behaviours' => 'behaviors', 'behavioural' => 'behavioral',
    'catalogue' => 'catalog', 'catalogues' => 'catalogs',
    'centre' => 'center', 'centred' => 'centered', 'centres' => 'centers', 'centring' => 'centering',
    'colour' => 'color', 'coloured' => 'colored', 'colouring' => 'coloring', 'colours' => 'colors',
    'credentialled' => 'credentialed',
    'defence' => 'defense', 'defences' => 'defenses',
    'dialled' => 'dialed', 'dialling' => 'dialing',
    'enrol' => 'enroll', 'enrolment' => 'enrollment', 'enrolments' => 'enrollments', 'enrols' => 'enrolls',
    'equalled' => 'equaled', 'equalling' => 'equaling',
    'favour' => 'favor', 'favoured' => 'favored', 'favours' => 'favors',
    'fibre' => 'fiber', 'fibres' => 'fibers',
    'flavour' => 'flavor', 'flavours' => 'flavors',
    'fulfil' => 'fulfill', 'fulfils' => 'fulfills',
    'funnelled' => 'funneled', 'funnelling' => 'funneling',
    'grey' => 'gray',
    'honour' => 'honor', 'honoured' => 'honored', 'honouring' => 'honoring', 'honours' => 'honors',
    'instalment' => 'installment', 'instalments' => 'installments',
    'judgement' => 'judgment', 'judgements' => 'judgments',
    'labelled' => 'labeled', 'labeller' => 'labeler', 'labelling' => 'labeling',
    'labour' => 'labor', 'laboured' => 'labored', 'labours' => 'labors',
    'licence' => 'license', 'licences' => 'licenses',
    'metre' => 'meter', 'metres' => 'meters',
    'mislabelled' => 'mislabeled', 'mislabelling' => 'mislabeling',
    'modelled' => 'modeled', 'modelling' => 'modeling',
    'neighbour' => 'neighbor', 'neighbourhood' => 'neighborhood', 'neighbouring' => 'neighboring', 'neighbours' => 'neighbors',
    'offence' => 'offense', 'offences' => 'offenses',
    'practise' => 'practice', 'practised' => 'practiced', 'practising' => 'practicing',
    'programme' => 'program', 'programmes' => 'programs',
    'relabelled' => 'relabeled', 'relabelling' => 'relabeling',
    'signalled' => 'signaled', 'signalling' => 'signaling',
    'skilful' => 'skillful',
    'storey' => 'story', 'storeys' => 'stories',
    'theatre' => 'theater', 'theatres' => 'theaters',
    'totalled' => 'totaled', 'totalling' => 'totaling',
    'travelled' => 'traveled', 'traveller' => 'traveler', 'travelling' => 'traveling',
    'tunnelled' => 'tunneled', 'tunnelling' => 'tunneling',
    'tyre' => 'tire', 'tyres' => 'tires',
    'unlabelled' => 'unlabeled',
    'wilful' => 'willful',
];

// The two families that keep producing new words. A list would go blind to the
// next `harmonise` nobody thought to add, so these are matched and the American
// words that happen to fit the shape are named instead — a far shorter list,
// and one that cannot grow by somebody writing British.
const BRITISH_ISE_PATTERN = '/^(?<stem>[a-z]{3,})is(?<tail>e|es|ed|ing|er|ers|ation|ations|able)$/';

const BRITISH_OUR_PATTERN = '/^[a-z]{2,}our(s|ed|ing|able)?$/';

/** @var list<string> words that end like the -ise family and are spelled this way in American English too */
const AMERICAN_ISE_WORDS = [
    'advertisable', 'advertise', 'advertised', 'advertiser', 'advertisers', 'advertises', 'advertising',
    'appraise', 'appraised', 'appraiser', 'appraises', 'appraising',
    'chastise', 'chastised', 'chastises', 'chastising',
    'comprise', 'comprised', 'comprises', 'comprising',
    'compromise', 'compromised', 'compromises', 'compromising',
    'concise', 'despise', 'despised', 'despises', 'despising',
    'devise', 'devised', 'devises', 'devising',
    'disguise', 'disguised', 'disguises', 'disguising',
    'enterprise', 'enterprises', 'excise', 'excised', 'excises', 'excising',
    'exercise', 'exercised', 'exercises', 'exercising',
    'expertise', 'franchise', 'franchised', 'franchises', 'franchising',
    'improvise', 'improvised', 'improvises', 'improvising',
    'incise', 'incised', 'incises', 'incising',
    'merchandise', 'merchandised', 'merchandises', 'merchandising',
    'pairwise', 'paradise', 'precise', 'premise', 'premised', 'premises',
    'promise', 'promised', 'promises', 'promising',
    'revise', 'revised', 'revises', 'revising',
    'supervise', 'supervised', 'supervises', 'supervising',
    'surprise', 'surprised', 'surprises', 'surprising',
    'unraised', 'unsurprised', 'unsurprising',
];

/** @var list<string> the same, for the -our family */
const AMERICAN_OUR_WORDS = [
    'bonjour', 'contour', 'contours', 'detour', 'detours', 'devour', 'devoured', 'devours',
    'flour', 'four', 'glamour', 'hour', 'hours', 'pour', 'poured', 'pouring', 'pours',
    'tour', 'toured', 'touring', 'tours', 'velour', 'your', 'yours',
];

// Symbols this repository did not declare. Renaming one does not rename the
// package that declares it — the reference simply stops resolving — so a
// vendor's own spelling binds here. PHPStan calls its own namespace `Analyser`.
const FOREIGN_SYMBOL_PREFIXES = ['PHPStan\\'];

/**
 * The British words one identifier carries, each with what should replace it.
 *
 * A word is only ever matched whole: `CounterpartyResolver` holds the letters
 * of `tyre` across the seam between `Counterparty` and `Resolver`, and a
 * substring reader renames it.
 *
 * @return array<string, string> British word => the American spelling
 */
function britishWordsIn(string $identifier): array
{
    foreach (FOREIGN_SYMBOL_PREFIXES as $prefix) {
        if (str_contains($identifier, $prefix)) {
            return [];
        }
    }

    $found = [];

    $words = preg_split(
        '/[^A-Za-z]+|(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
        $identifier,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    foreach ($words ?: [] as $word) {
        $lower = strtolower($word);

        if (isset(BRITISH_IDENTIFIER_WORDS[$lower])) {
            $found[$lower] = BRITISH_IDENTIFIER_WORDS[$lower];

            continue;
        }

        if (preg_match(BRITISH_ISE_PATTERN, $lower, $match) === 1 && ! in_array($lower, AMERICAN_ISE_WORDS, true)) {
            $found[$lower] = $match['stem'].'iz'.$match['tail'];

            continue;
        }

        if (preg_match(BRITISH_OUR_PATTERN, $lower) === 1 && ! in_array($lower, AMERICAN_OUR_WORDS, true)) {
            $found[$lower] = substr($lower, 0, -3).'or'.substr($lower, strpos($lower, 'our') + 3);
        }
    }

    return $found;
}

/**
 * Every name the machine resolves in one file, and nothing else.
 *
 * Comments are dropped before the walk sees them and a quoted string is never
 * an identifier token, so the two surfaces the convention leaves British are
 * unreachable from here by construction rather than by an exception list.
 *
 * @return list<array{line: int, identifier: string}>
 */
function resolvedNamesIn(string $path, string $source): array
{
    $names = [];

    foreach (token_get_all(BladePhpSource::forPath($path, $source)) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (! in_array($token[0], [T_STRING, T_VARIABLE, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            continue;
        }

        $names[] = ['line' => $token[2], 'identifier' => $token[1]];
    }

    return $names;
}

it('spells every name the machine resolves the way American English does', function (): void {
    $offenders = [];
    $walked = 0;

    $self = str_replace(base_path().'/', '', __FILE__);

    foreach (RepoTree::files(RepoTree::EVERY_PHP_FILE) as $path) {
        $file = str_replace(RepoTree::root().'/', '', $path);

        // This file spells every word it forbids, as the data it forbids them by.
        if (str_ends_with($file, $self)) {
            continue;
        }

        $walked++;

        foreach (resolvedNamesIn($path, (string) file_get_contents($path)) as $name) {
            foreach (britishWordsIn($name['identifier']) as $british => $american) {
                $offenders[] = $file.':'.$name['line'].'  '.$name['identifier'].'  ('.$british.' -> '.$american.')';
            }
        }
    }

    expect($walked)->toBeGreaterThan(0, 'the walk opened no file at all, so the clean answer below is an empty scan.');

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n  ", [
        'These names carry a British spelling. A name the machine resolves is American here — the '
        .'module this application categorizes through is called Categorization, so every namespace, '
        .'provider and doc reference in it is already committed to that spelling:',
        ...array_values(array_unique($offenders)),
        '',
        'Rename the symbol and its references. If the word is a vendor\'s own, add its namespace to '
        .'FOREIGN_SYMBOL_PREFIXES; if American English really spells it this way, add the word to '
        .'AMERICAN_ISE_WORDS or AMERICAN_OUR_WORDS.',
    ]));
});

it('leaves the English a reader is shown British, under a key that is not', function (): void {
    // A live line rather than a fixture: the rule's two halves meet inside one
    // array entry, so a sweep that americanized the copy along with the key
    // fails here rather than shipping.
    $sidebar = require RepoTree::root().'/Modules/Core/Resources/lang/en/sidebar.php';

    expect($sidebar)->toHaveKey('section_organize')
        ->and($sidebar['section_organize'])->toBe('ORGANISE');

    // And the reader above would have caught that value had it been a name.
    // Without this, a reader that quietly stopped recognizing `organise`
    // reports the same clean tree a correct one does.
    expect(britishWordsIn('sectionOrganise'))->toBe(['organise' => 'organize']);
});

it('reads a British name planted in a source, and leaves the comment and the copy beside it alone', function (): void {
    $planted = <<<'PHP'
        <?php
        final class ColourPalette
        {
            public function normalise(string $behaviour): string
            {
                return $behaviour;
            }
        }
        PHP;

    $prose = <<<'PHP'
        <?php
        // The colour of a row is the behaviour the reader organised it under,
        // and the enrolment that labelled it travelled with the artefact.
        /** @var string $x A centre, in metres, analysed once. */
        $x = 'Colour, behaviour and organisation stay British where a reader reads them.';
        PHP;

    $foreign = <<<'PHP'
        <?php
        use PHPStan\Analyser\Scope;
        PHP;

    $spanning = <<<'PHP'
        <?php
        $a = new CounterpartyResolver();
        $b = advertiseEnterprise($promised);
        PHP;

    $found = [];

    foreach (resolvedNamesIn('planted.php', $planted) as $name) {
        $found = [...$found, ...array_keys(britishWordsIn($name['identifier']))];
    }

    $inProse = [];

    foreach (resolvedNamesIn('prose.php', $prose) as $name) {
        $inProse = [...$inProse, ...array_keys(britishWordsIn($name['identifier']))];
    }

    $inForeign = [];

    foreach (resolvedNamesIn('foreign.php', $foreign) as $name) {
        $inForeign = [...$inForeign, ...array_keys(britishWordsIn($name['identifier']))];
    }

    $inSpanning = [];

    foreach (resolvedNamesIn('spanning.php', $spanning) as $name) {
        $inSpanning = [...$inSpanning, ...array_keys(britishWordsIn($name['identifier']))];
    }

    expect(array_unique($found))->toEqualCanonicalizing(
        ['colour', 'normalise', 'behaviour'],
        'a British class name, method name, parameter and variable were planted and the reader has to report all three words'
    )
        ->and($inProse)->toBe([], 'a comment and a quoted sentence are the two surfaces that stay British, and neither is an identifier')
        ->and($inForeign)->toBe([], 'PHPStan declares its own Analyser namespace, and renaming a vendor symbol only stops it resolving')
        ->and($inSpanning)->toBe([], 'CounterpartyResolver holds the letters of `tyre` across a word seam, and advertise and promised are American as they stand');
});

it('reads a name inside a Blade island, where the PHP tokenizer alone is blind', function (): void {
    // Two call sites were renamed in PHP and missed in Blade by exactly this
    // blindness: `@if (...)` and `@php ... @endphp` reach token_get_all as one
    // run of inline HTML, and every symbol inside them is invisible.
    $blade = <<<'BLADE'
        @php
            $day = SafeDate::normalisedDayOrNull($row);
        @endphp
        @if (OAuthAlertKind::promptsReauthorisation($alert))
            <span>{{ $day }}</span>
        @endif
        BLADE;

    $found = [];

    foreach (resolvedNamesIn('island.blade.php', $blade) as $name) {
        $found = [...$found, ...array_keys(britishWordsIn($name['identifier']))];
    }

    expect(array_unique($found))->toEqualCanonicalizing(
        ['normalised', 'reauthorisation'],
        'a directive argument and an @php island both hold names, and a reader that enters neither passes every template'
    );
});
