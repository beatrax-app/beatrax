<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;

// The first cash entry mints an account, and the cash book named it "Cash" in
// English whatever the reader was reading in. Being data rather than a key, it
// stayed English on every screen that draws an account name. Rewriting it is
// what lets the reader's own word win without those screens changing.
//
// The literals are frozen copies of what the writer wrote, not imports of its
// constants: what this has to recognise is the wording already on disk.
return new class extends ModuleMigration
{
    private const string ENGLISH_NAME = 'Cash';

    private const string CASH_KIND = 'cash';

    private const string OWN_IBAN_PREFIX = 'CASH';

    private const int OWN_IBAN_DIGITS = 12;

    private const string WORD_KEY = 'import::payment_type.cash';

    public function up(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('accounts') || ! $schema->hasTable('users')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());

        // `kind`, `iban` and `name` are plaintext on this table, so this reads
        // the same on an encrypted install as on a bare one. The IBAN is the
        // evidence: `CASH` followed by the zero-padded reader id is written by
        // the cash book and by nothing else, so it says the app minted the row
        // rather than a person. An account a reader called "Cash" themselves
        // was named in the import wizard and carries the statement's own IBAN.
        $rows = $connection->table('accounts')
            ->where('kind', self::CASH_KIND)
            ->where('name', self::ENGLISH_NAME)
            ->orderBy('id')
            ->get(['id', 'user_id', 'iban']);

        $connection->transaction(function () use ($connection, $rows): void {
            foreach ($rows as $row) {
                /** @var stdClass $row */
                $word = $this->wordFor($connection, $row);
                if ($word === null) {
                    continue;
                }

                $connection->table('accounts')->where('id', $row->id)->update(['name' => $word]);
            }
        });
    }

    public function down(): void
    {
        // Not reversed. Putting the English back re-freezes the account in a
        // language the reader never chose, which is the defect this repaired,
        // and the word this wrote is the app's own for the same money.
    }

    // Null when the row is not one the cash book minted, when the reader named
    // no language — "system" is the absence of an answer, and guessing one is
    // how this defect started — or when their language says what is already
    // stored, so a second run writes nothing.
    private function wordFor(Connection $connection, stdClass $row): ?string
    {
        if (! is_numeric($row->user_id) || $row->iban !== self::ownIban((int) $row->user_id)) {
            return null;
        }

        $locale = $connection->table('users')->where('id', $row->user_id)->value('locale');
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        /** @var Translator $translator */
        $translator = Container::getInstance()->make(Translator::class);
        $word = $translator->get(self::WORD_KEY, [], $locale);

        return is_string($word) && $word !== self::WORD_KEY && $word !== self::ENGLISH_NAME ? $word : null;
    }

    private static function ownIban(int $userId): string
    {
        return self::OWN_IBAN_PREFIX.str_pad((string) $userId, self::OWN_IBAN_DIGITS, '0', STR_PAD_LEFT);
    }
};
