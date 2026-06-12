<?php

declare(strict_types=1);

use Modules\Tax\Public\Services\TaxCorpusLoader;

/*
 * Unit tests for TaxCorpusLoader — the built-in deduction category corpus.
 *
 * Status: RED — skipped. Full implementation ships in Plan 04.
 *
 * These tests encode the requirements for TAX-03 (Dutch corpus seeding)
 * so Plan 04 can implement against this spec.
 */

it('loads the Dutch corpus for country code "NL"', function (): void {
    // TODO Plan 04: assert TaxCorpusLoader returns non-empty array of category
    // definitions for country_code=NL.
})->skip('built in Plan 04');

it('returns an empty list for an unsupported country code', function (): void {
    // TODO Plan 04: assert empty result for country_code=XX (not in corpus).
})->skip('built in Plan 04');

it('each corpus entry has name, short_name, corpus_key, country_code and status=active', function (): void {
    // TODO Plan 04: assert each returned entry has required keys with correct types.
})->skip('built in Plan 04');

it('seeding a user with NL corpus idempotently creates categories once', function (): void {
    // TODO Plan 04: seed twice; assert exactly N rows in tax_deduction_categories,
    // not 2*N (upsert on corpus_key+user_id).
})->skip('built in Plan 04');
