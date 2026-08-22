<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Public\Support\UniqueSlug;
use Modules\Ledger\Public\Services\AccountSlugResolver;

// Two account-creation paths appended the last 6 or 8 characters of the IBAN
// to the slug to keep two accounts off one unique(user_id, slug) row. That put
// an account identifier in a column that is plaintext at rest, which is the
// rule `counterparties` already keeps. A numeric suffix separates them just as
// well. `accounts.name`, `.slug` and `.iban` are all plaintext, so this needs
// no key and runs on a locked device.
return new class extends Migration
{
    // The shorter of the two runs the leaky generators wrote (6 and 8).
    // Below that a name segment could end in the IBAN's own last characters
    // by coincidence, and the rewrite would be a rename rather than a fix.
    private const int MIN_TAIL = 6;

    // The same structural bound AccountNamer enforced before it ever wrote a
    // tailed slug. Without it the synthetic own-account IBANs match their own
    // slug suffix — `PAYPAL` ends with `paypal` — and a clean slug is rewritten.
    private const string REAL_IBAN = '/^[A-Z0-9]{15,34}$/';

    public function up(): void
    {
        if (! Schema::getFacadeRoot()->hasTable('accounts')) {
            return;
        }

        foreach ($this->userIds() as $userId) {
            $this->reslugUser($userId);
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: putting the IBAN tail back would restore
        // the leak, and nothing reads an account slug as a key — there is no
        // /accounts/{slug} route and no production lookup by it.
    }

    /**
     * @return list<int|null>
     */
    private function userIds(): array
    {
        $ids = [];
        foreach (DB::table('accounts')->distinct()->orderBy('user_id')->get(['user_id']) as $row) {
            $ids[] = is_numeric($row->user_id) ? (int) $row->user_id : null;
        }

        return $ids;
    }

    // Per user, because the uniqueness the walk has to respect is per user.
    private function reslugUser(?int $userId): void
    {
        $rows = $this->rowsFor($userId);

        /** @var array<string, true> $taken */
        $taken = [];
        foreach ($rows as $row) {
            $taken[$row['slug']] = true;
        }

        foreach ($rows as $row) {
            if (! self::carriesIbanTail($row['slug'], $row['iban'])) {
                continue;
            }

            unset($taken[$row['slug']]);
            $replacement = self::firstFree($row['name'], $taken);
            $taken[$replacement] = true;

            DB::table('accounts')->where('id', $row['id'])->update(['slug' => $replacement]);
        }
    }

    /**
     * @return list<array{id: int, name: string, slug: string, iban: string}>
     */
    private function rowsFor(?int $userId): array
    {
        $query = DB::table('accounts')->orderBy('id');
        if ($userId === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $userId);
        }

        $rows = [];
        foreach ($query->get(['id', 'name', 'slug', 'iban']) as $row) {
            $rows[] = [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'name' => is_string($row->name) ? $row->name : '',
                'slug' => is_string($row->slug) ? $row->slug : '',
                'iban' => is_string($row->iban) ? $row->iban : '',
            ];
        }

        return $rows;
    }

    // Deliberately blind to the name: `accounts.name` is lww-synced and `slug`
    // is not, so a rename leaves a slug the name no longer prefixes — and an
    // anchored test skipped exactly those rows, silently keeping the tail. The
    // final segment being this row's own IBAN ending is proof enough on its own.
    private static function carriesIbanTail(string $slug, string $iban): bool
    {
        $lastHyphen = strrpos($slug, '-');
        if ($lastHyphen === false || preg_match(self::REAL_IBAN, $iban) !== 1) {
            return false;
        }

        $tail = substr($slug, $lastHyphen + 1);

        return strlen($tail) >= self::MIN_TAIL
            && preg_match('/^[a-z0-9]+$/', $tail) === 1
            && str_ends_with(strtolower($iban), $tail);
    }

    // Through the ledger's own producer, not a copy of it: this writes into
    // the same unique(user_id, slug) the runtime walk writes into, and a
    // second spelling of the base or of the suffix would hand out a slug the
    // other one believes is free.
    /**
     * @param  array<string, true>  $taken
     */
    private static function firstFree(string $name, array $taken): string
    {
        return UniqueSlug::walk(
            AccountSlugResolver::slugify($name),
            static fn (string $slug): bool => ! isset($taken[$slug]),
        );
    }
};
