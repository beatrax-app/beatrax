<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\FX\Public\Dto\ConvertedTotal;
use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\RepoTree;

// A converted figure has two halves to disclose and the tree only ever wrote
// one of them. Twenty-eight templates named the currencies they had LEFT OUT
// and none named the rate they had converted the rest at, because
// CrossCurrencyTotal::ratesTo() answered an array<string, string> and dropped
// the source and the as-of date one call before any surface could read them.
// A ¥480,000 expense moved the dashboard Out tile by EUR 3,016.97 at a bundled
// rate published ninety-nine days earlier, and the tile said "€5,118.79".
// @link ../../.docs/features/fx/architecture.md#a-converted-figure-carries-the-rate-that-made-it

const FX_DISCLOSURE_COMPONENT = 'Modules/Core/Resources/views/components/fx-disclosure.blade.php';

const FX_DISCLOSURE_MOUNT = '<x-core::fx-disclosure';

// The half that WAS written everywhere. One template renders it now, and a
// second copy of this line is a surface disclosing what it left out without
// disclosing what it converted at — the shape this rule is about.
const FX_UNCONVERTED_COPY = 'core::money.not_converted';

// A declared property, never a parameter: `alsoUnpriced(array $unconverted)`
// takes the list to merge it into another, which is not a figure answering for
// itself. The terminator is what tells the two apart.
const FX_UNCONVERTED_PROPERTY = '/^[ \t]*public[ \t]+(?:readonly[ \t]+)?[^(){}=;]*\$(\w*[Uu]nconverted\w*)[ \t]*[,;=]/m';

// The type that carries the other half. ConvertedTotal is the one class that
// holds a RateSet directly and projects it on demand, which is why the name is
// read as text rather than resolved: every other class here holds the
// projection.
const FX_DISCLOSURE_TYPE = 'ConversionDisclosure';

// A class that cannot carry the rate, with the file that answers for why and a
// `proves` pattern re-run against it. When the reason stops being true the
// entry goes, and the accounting below reports an exemption nothing reaches.
const FX_DISCLOSURE_EXEMPT = [
    'Modules/FX/Public/Dto/ConversionDisclosure.php' => [
        'reason' => 'the disclosure itself, which is where the codes and the rates meet',
        'file' => 'Modules/FX/Public/Dto/ConversionDisclosure.php',
        'proves' => '/final readonly class ConversionDisclosure/',
    ],
    'Modules/FX/Public/Dto/ConvertedTotal.php' => [
        'reason' => 'holds the RateSet itself and projects the disclosure on demand, so it is the source of every other entry here rather than a holder of one',
        'file' => 'Modules/FX/Public/Dto/ConvertedTotal.php',
        'proves' => '/public RateSet \$rates/',
    ],
    'Modules/Forecasting/Public/Dto/ForecastDto.php' => [
        'reason' => 'read back out of a stored projection run, whose result_json records the codes the fold could not price and not the rates it priced the rest at; the curve can disclose a rate once that format carries one',
        'file' => 'Modules/Forecasting/Internal/Mapping/ForecastDtoMapper.php',
        'proves' => '/unconverted_currencies.{0,4}\?\?/',
    ],
    'Modules/Forecasting/Internal/Pipeline/DailyFoldResult.php' => [
        'reason' => 'the fold holds the rate set, but the only reader of this result writes it to result_json, and what that format does not carry cannot come back out of it',
        'file' => 'Modules/Forecasting/Internal/Pipeline/ProjectionPipeline.php',
        'proves' => '/unconverted_currencies.{0,6}=>/',
    ],
];

/**
 * @return array<string, string> repo-relative path => template source, comments stripped
 */
function fxDisclosureBlades(): array
{
    $blades = [];

    foreach (RepoTree::relativeFiles(RepoTree::EVERY_BLADE_VIEW) as $relative) {
        $blades[$relative] = PatternScan::replace(
            '~\{\{--.*?--\}\}~s',
            '',
            (string) file_get_contents(RepoTree::root().'/'.$relative),
        );
    }

    return $blades;
}

/**
 * Every class carrying a reader-facing list of what a rate could not reach.
 *
 * @return array<string, list<string>> repo-relative path => the properties naming it
 */
function fxUnconvertedCarriers(): array
{
    $carriers = [];

    foreach (BackendSourceFiles::all() as $path) {
        $properties = PatternScan::all(FX_UNCONVERTED_PROPERTY, (string) file_get_contents($path))[1];

        if ($properties === []) {
            continue;
        }

        $properties = array_values(array_unique($properties));
        sort($properties);
        $carriers[str_replace(base_path().'/', '', $path)] = $properties;
    }

    ksort($carriers);

    return $carriers;
}

it('renders the not-converted half through the one component that renders the other half too', function (): void {
    $blades = fxDisclosureBlades();

    // toHaveKey's second argument is the expected VALUE, not a message, so the
    // presence of the component is asserted on its own.
    expect(array_key_exists(FX_DISCLOSURE_COMPONENT, $blades))
        ->toBeTrue('The shared disclosure component is not in the walk, so nothing below measured anything.');

    $offenders = [];

    foreach ($blades as $relative => $source) {
        if ($relative === FX_DISCLOSURE_COMPONENT || ! str_contains($source, FX_UNCONVERTED_COPY)) {
            continue;
        }

        $offenders[] = $relative;
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "These templates write the \"not converted\" line themselves:\n  ".implode("\n  ", $offenders)
        ."\n\nRender ".FX_DISCLOSURE_MOUNT.' instead. It carries both halves, so a surface cannot name what it left out '
        .'while staying silent about the rate it converted the rest at.'
    );
});

it('carries the rate as far as every figure that carries what no rate reached', function (): void {
    $carriers = fxUnconvertedCarriers();

    // Nineteen classes carry the list today. A walk that found a handful of
    // them would report the tree compliant on the strength of the handful.
    expect(count($carriers))->toBeGreaterThan(12, 'Read '.count($carriers).' classes naming an unconverted list, too few to have proved anything.');

    $offenders = [];
    $reached = [];

    foreach ($carriers as $relative => $properties) {
        if (array_key_exists($relative, FX_DISCLOSURE_EXEMPT)) {
            $reached[$relative] = true;

            continue;
        }

        if (str_contains((string) file_get_contents(base_path($relative)), FX_DISCLOSURE_TYPE)) {
            continue;
        }

        $offenders[] = $relative.' — $'.implode(', $', $properties);
    }

    expect($offenders)->toBe(
        [],
        "These figures name the currencies they left out and cannot name the rate they converted the rest at:\n  "
        .implode("\n  ", $offenders)
        ."\n\nThe producer already holds the rate set: take a ".FX_DISCLOSURE_TYPE.' through to the surface, '
        .'and the shared component renders both halves from it.'
    );

    $granted = array_keys(FX_DISCLOSURE_EXEMPT);
    $found = array_keys($reached);
    sort($granted);
    sort($found);

    // An exemption nothing reaches any more excuses nothing, and would sit here
    // excusing whatever came to be written at that path later.
    expect($found)->toBe($granted, 'An exempted carrier is no longer reached by the walk that granted it: '
        .implode(', ', array_diff($granted, $found)));
});

it('still holds each exempted carrier to the reason that earned it', function (): void {
    foreach (FX_DISCLOSURE_EXEMPT as $relative => $pin) {
        $source = (string) file_get_contents(base_path($pin['file']));

        expect(PatternScan::matches($pin['proves'], $source))
            ->toBeTrue($relative.' no longer reads as "'.$pin['reason'].'" in '.$pin['file']);
    }
});

it('cannot build a converted total that has no rates to disclose', function (): void {
    $constructor = (new ReflectionClass(ConvertedTotal::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    $rates = null;

    foreach ($constructor->getParameters() as $parameter) {
        if ($parameter->getName() === 'rates') {
            $rates = $parameter;
        }
    }

    expect($rates)->not->toBeNull('ConvertedTotal no longer carries the rates that built it, so no surface downstream can disclose them.');
    expect($rates->isOptional())->toBeFalse(
        'The rates ConvertedTotal was built from are optional again. A default makes "I converted, and I will not say at what" '
        .'constructible, which is the state twenty-eight surfaces were in.'
    );
});

it('reads a declared property rather than any parameter that happens to be named for one', function (): void {
    $declared = <<<'PHP'
        public readonly array $unconvertedCurrencies = [],
        public array $unconverted;
        public readonly ?ConversionDisclosure $conversion = null,
        PHP;

    expect(PatternScan::all(FX_UNCONVERTED_PROPERTY, $declared)[1])
        ->toBe(['unconvertedCurrencies', 'unconverted'], 'both spellings of a declared property are read, and the disclosure beside them is not one');

    $parameter = '    public function alsoUnpriced(array $unconverted): array';

    expect(PatternScan::all(FX_UNCONVERTED_PROPERTY, $parameter)[1])
        ->toBe([], 'a method taking the list to merge it elsewhere is not a figure answering for itself');
});
