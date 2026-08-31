<?php

declare(strict_types=1);

use Modules\Import\Internal\Parsers\Banking\Camt053PaymentTypeHinter;
use Modules\Import\Internal\Parsers\Banking\Mt940PaymentTypeHinter;
use Modules\Import\Internal\Parsers\Csv\PositionalCsvPaymentTypeHinter;
use Modules\Import\Internal\Parsers\DescriptionKeywordFallbackHinter;
use Modules\Import\Internal\Parsers\Ics\IcsPdfPaymentTypeHinter;
use Modules\Import\Internal\Parsers\Paypal\PaypalCsvPaymentTypeHinter;
use Modules\Import\Public\Contracts\PaymentTypeHinter;

it('binds exactly six PaymentTypeHinter implementations under the import.payment_type_hinter container tag', function (): void {
    /** @var iterable<PaymentTypeHinter> $tagged */
    $tagged = $this->app->tagged('import.payment_type_hinter');
    $hinters = [];
    foreach ($tagged as $hinter) {
        $hinters[] = $hinter;
    }

    expect($hinters)->toHaveCount(6);
});

it('binds every tagged hinter to the PaymentTypeHinter contract', function (): void {
    /** @var iterable<PaymentTypeHinter> $tagged */
    $tagged = $this->app->tagged('import.payment_type_hinter');
    foreach ($tagged as $hinter) {
        expect($hinter)->toBeInstanceOf(PaymentTypeHinter::class);
    }
});

it('emits the description-keyword fallback hinter as the LAST entry so tie-break order falls through correctly', function (): void {
    /** @var iterable<PaymentTypeHinter> $tagged */
    $tagged = $this->app->tagged('import.payment_type_hinter');
    $hinters = [];
    foreach ($tagged as $hinter) {
        $hinters[] = $hinter;
    }

    $last = $hinters[count($hinters) - 1];
    expect($last)->toBeInstanceOf(DescriptionKeywordFallbackHinter::class);
});

it('emits the tagged hinters in the documented registration order', function (): void {
    /** @var iterable<PaymentTypeHinter> $tagged */
    $tagged = $this->app->tagged('import.payment_type_hinter');
    $classes = [];
    foreach ($tagged as $hinter) {
        $classes[] = $hinter::class;
    }

    expect($classes)->toBe([
        Camt053PaymentTypeHinter::class,
        Mt940PaymentTypeHinter::class,
        PositionalCsvPaymentTypeHinter::class,
        IcsPdfPaymentTypeHinter::class,
        PaypalCsvPaymentTypeHinter::class,
        DescriptionKeywordFallbackHinter::class,
    ]);
});
