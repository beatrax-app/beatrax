<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;

// The corpus seeds a deduction category's name in the jurisdiction's language,
// so a reader whose locale is not that language has been shown "Zorgkosten"
// with no key to fall back from. `corpus_key` is the key, and it is already on
// the row — but it survives a rename, so on its own it cannot tell the corpus's
// own wording from wording the user typed over it. This flag draws that line,
// exactly as `categories.name_is_default` already does for budget categories.
//
// The backfill does not assume: it re-reads each row's own corpus entry and
// marks the row a default only where the stored name still equals what the
// corpus wrote. A user who renamed a seeded category keeps their name, and it
// keeps rendering verbatim in every language.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_deduction_categories', static function (Blueprint $table): void {
            $table->boolean('name_is_default')->default(false);
        });

        $rows = DB::table('tax_deduction_categories')
            ->whereNotNull('corpus_key')
            ->get(['id', 'name', 'corpus_key', 'country_code']);

        $seeded = [];
        foreach ($rows as $row) {
            $country = is_string($row->country_code) ? $row->country_code : '';
            $seeded[$country] ??= $this->corpusNames($country);

            $key = is_string($row->corpus_key) ? $row->corpus_key : '';
            if (($seeded[$country][$key] ?? null) !== $row->name) {
                continue;
            }

            DB::table('tax_deduction_categories')
                ->where('id', $row->id)
                ->update(['name_is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('tax_deduction_categories', static function (Blueprint $table): void {
            $table->dropColumn('name_is_default');
        });
    }

    /** @return array<string, string> corpus key => the name that corpus seeds for it */
    private function corpusNames(string $countryCode): array
    {
        if ($countryCode === '') {
            return [];
        }

        $names = [];
        foreach (app(TaxCorpusLoader::class)->loadForCountry($countryCode) as $entry) {
            $key = $entry['key'] ?? null;
            $name = $entry['name'] ?? null;
            if (is_string($key) && is_string($name)) {
                $names[$key] = $name;
            }
        }

        return $names;
    }
};
