<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use Iban\Validation\Validator as IbanValidator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\ClassificationRule;
use Modules\Community\Public\Services\ClassificationRuleProvider;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Services\UserCountry;
use Modules\Core\Public\Support\IdReadBack;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;
use Modules\Counterparties\Internal\Enums\CounterpartySubcategory;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Dto\CounterpartyResolutionDto;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Events\CounterpartyResolved;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md
 */
final readonly class CounterpartyResolverService implements CounterpartyResolver
{
    private const array MERCHANT_NAME_MARKERS = [
        'BV',
        'B.V.',
        'B.V',
        'NV',
        'N.V.',
        'LTD',
        'LIMITED',
        'INC',
        'INC.',
        'GMBH',
        'AG',
        'SARL',
        'SA',
        'PLC',
        'CORP',
        'CO.',
        'LLC',
    ];

    private const array PERSONAL_TRANSACTION_TYPES = [
        TransactionType::TransferIn->value,
        TransactionType::TransferOut->value,
    ];

    public function __construct(
        private DatabaseManager $db,
        private ResolvesKnownCounterpartyIban $aliasBridge,
        private MerchantNameResolver $merchantResolver,
        private Dispatcher $events,
        private IbanValidator $ibanValidator,
        private ClassificationRuleProvider $ruleProvider,
        private CorpusPatternMatcher $matcher,
        private SensitiveColumnCodec $codec,
        // A factory, not the session: resolving a session builds the encrypter,
        // and Artisan constructs this class merely to list a console command.
        private SessionFactory $session,
        private CounterpartySlugResolver $slugResolver,
        private UserCountry $countries,
    ) {}

    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto
    {
        $userId = $user->id;

        // The order is the classification rule, not an implementation detail:
        // first match wins, so reordering this list changes what a row
        // resolves to.
        $strategies = [
            fn (): ?CounterpartyResolutionDto => $this->resolveSelfAccount($tx, $userId),
            fn (): ?CounterpartyResolutionDto => $this->resolveKnownBridge($tx, $user, $userId),
            fn (): ?CounterpartyResolutionDto => $this->resolveMerchant($tx, $userId),
            fn (): ?CounterpartyResolutionDto => $this->resolvePersonal($tx, $userId),
            fn (): ?CounterpartyResolutionDto => $this->resolveGovernment($tx, $userId),
            fn (): ?CounterpartyResolutionDto => $this->resolveBankFee($tx, $userId),
        ];

        foreach ($strategies as $strategy) {
            $resolved = $strategy();
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->resolveUnknown($tx, $userId);
    }

    private function resolveSelfAccount(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        $iban = $this->normaliseIban($tx->counterpartyIban);
        if ($iban === null) {
            return null;
        }

        $isSelf = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->where('iban', $iban)
            ->exists();

        if (! $isSelf) {
            return null;
        }

        return new CounterpartyResolutionDto(
            type: CounterpartyType::SelfAccount->value,
            displayName: $tx->counterpartyName ?? $iban,
            slug: '',
            iban: $iban,
            merchantName: null,
            metadata: [],
            counterpartyId: null,
        );
    }

    private function resolveKnownBridge(
        CanonicalTransaction $tx,
        User $user,
        int $userId,
    ): ?CounterpartyResolutionDto {
        $iban = $this->normaliseIban($tx->counterpartyIban);
        if ($iban === null) {
            return null;
        }

        $account = $this->aliasBridge->resolveAccount($iban, $user->id);
        if ($account === null) {
            return null;
        }

        // The bridge contract returns the user's own Account (it was built for
        // Chains routing), so the institution's legal name comes from
        // known_counterparty_ibans.notes rather than from widening that contract.
        $notes = $this->db->connection()
            ->table('known_counterparty_ibans')
            ->where('user_id', $userId)
            ->where('real_iban', $iban)
            ->value('notes');

        $displayName = is_string($notes) && trim($notes) !== ''
            ? trim($notes)
            : ($tx->counterpartyName ?? $iban);

        return $this->upsert(
            userId: $userId,
            type: CounterpartyType::Bank->value,
            displayName: $displayName,
            iban: $iban,
            merchantName: null,
            metadata: [
                'bridge_account_kind' => $account->kind,
                'institution_iban' => $iban,
            ],
        );
    }

    private function resolveMerchant(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        $description = $tx->description;
        if ($description === null || trim($description) === '') {
            return null;
        }

        $merchantName = $this->merchantResolver->resolve($description, $userId);
        if ($merchantName === null) {
            return null;
        }

        // The corpus reads the description, so the name it returns stands in
        // for a counterparty the file did not name and never overrules one it
        // did — the precedence the preview column already shows. merchant_name
        // keeps the resolved name, and the slug still follows the display name.
        $namedInFile = is_string($tx->counterpartyName) ? trim($tx->counterpartyName) : '';

        return $this->upsert(
            userId: $userId,
            type: CounterpartyType::Merchant->value,
            displayName: $namedInFile === '' ? $merchantName : $namedInFile,
            iban: $this->normaliseIban($tx->counterpartyIban),
            merchantName: $merchantName,
            metadata: [],
        );
    }

    private function resolvePersonal(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        $iban = $this->normaliseIban($tx->counterpartyIban);
        $name = $tx->counterpartyName;

        // The null checks stay here rather than moving into the predicate so
        // both values are narrowed to string for the upsert below.
        if ($iban === null || $name === null || ! $this->isPersonalTransfer($tx, $iban, $name)) {
            return null;
        }

        return $this->upsert(
            userId: $userId,
            type: CounterpartyType::Personal->value,
            // Privacy default: the slug derives from the display name alone,
            // never the IBAN. Guarded by PrivacyDefaultsTest.
            displayName: trim($name),
            iban: $iban,
            merchantName: null,
            metadata: [],
        );
    }

    private function isPersonalTransfer(CanonicalTransaction $tx, string $iban, string $name): bool
    {
        return in_array($tx->type, self::PERSONAL_TRANSACTION_TYPES, true)
            && $this->ibanValidator->validate($iban)
            && trim($name) !== ''
            && $this->looksLikePersonalName($name);
    }

    // Empty when the reader has named no country. Read once per transaction,
    // since the two rule tiers below both ask.
    private function regionFor(int $userId): string
    {
        return $this->countries->current($userId);
    }

    // A merchant can be international; a government body and a bank's fee
    // cannot. Naming one without a country asserted a Flemish agency at a Dutch
    // reader, because ZORGPREMIE is the ordinary Dutch word for a health
    // premium and only Belgium's file defines it. Unknown is the honest answer.
    private function namesANationalInstitution(int $userId): bool
    {
        return $this->regionFor($userId) !== '';
    }

    private function resolveGovernment(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        if (! $this->namesANationalInstitution($userId)) {
            return null;
        }

        return $this->resolveByRules(
            $tx,
            $userId,
            $this->ruleProvider->governmentRules($this->regionFor($userId)),
            CounterpartyType::Government->value,
            function (ClassificationRule $rule) use ($tx): array {
                [$displayName, $defaultName] = $this->governmentDisplayName($rule, $tx);

                return [
                    $displayName,
                    ['matched_keyword' => $rule->pattern],
                    $defaultName,
                ];
            },
        );
    }

    private function resolveBankFee(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        if (! $this->namesANationalInstitution($userId)) {
            return null;
        }

        return $this->resolveByRules(
            $tx,
            $userId,
            $this->ruleProvider->bankFeeRules($this->regionFor($userId)),
            CounterpartyType::Bank->value,
            static fn (ClassificationRule $rule): array => [
                $rule->name ?? CounterpartyDefaultName::storedName(CounterpartyDefaultName::BANK_FEE),
                [
                    CounterpartyMetadataKey::Subcategory->value => CounterpartySubcategory::Fee->value,
                    'matched_keyword' => $rule->pattern,
                ],
                $rule->name === null ? CounterpartyDefaultName::BANK_FEE : null,
            ],
        );
    }

    // The third element is the app's own name for the row when the rule gave
    // none, so the reader gets it in their language rather than in whichever
    // one the import ran in.
    /**
     * @param  list<ClassificationRule>  $rules
     * @param  callable(ClassificationRule): array{0: string, 1: array<string, mixed>, 2: string|null}  $build
     */
    private function resolveByRules(
        CanonicalTransaction $tx,
        int $userId,
        array $rules,
        string $type,
        callable $build,
    ): ?CounterpartyResolutionDto {
        $haystack = $this->haystack($tx);
        if ($haystack === '') {
            return null;
        }

        foreach ($rules as $rule) {
            if (! $this->matcher->matches($rule->pattern, $haystack)) {
                continue;
            }

            [$displayName, $metadata, $defaultName] = $build($rule);

            return $this->upsert(
                userId: $userId,
                type: $type,
                displayName: $displayName,
                iban: $this->normaliseIban($tx->counterpartyIban),
                merchantName: null,
                metadata: $metadata,
                defaultName: $defaultName,
            );
        }

        return null;
    }

    private function resolveUnknown(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        $iban = $this->normaliseIban($tx->counterpartyIban);
        $name = $tx->counterpartyName;
        $description = $tx->description;

        $hasName = $name !== null && trim($name) !== '';
        $hasIban = $iban !== null;
        $hasDescription = $description !== null && trim($description) !== '';

        // The writer layer still persists the row; it just carries no
        // counterparty_id, and the triage queue has nothing to show for it.
        if (! $hasName && ! $hasIban && ! $hasDescription) {
            return null;
        }

        $defaultName = null;
        if ($name !== null && trim($name) !== '') {
            $displayName = trim($name);
        } elseif ($iban !== null) {
            $displayName = $iban;
        } else {
            $defaultName = CounterpartyDefaultName::UNKNOWN;
            $displayName = CounterpartyDefaultName::storedName($defaultName);
        }

        return $this->upsert(
            userId: $userId,
            type: CounterpartyType::Unknown->value,
            displayName: $displayName,
            iban: $iban,
            merchantName: null,
            metadata: [],
            defaultName: $defaultName,
        );
    }

    // $defaultName names the app's own word when the row is one this pass had
    // to name itself, and is written with the name it belongs to. The refresh
    // branch never re-asserts it: that branch leaves display_name alone.
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function upsert(
        int $userId,
        string $type,
        string $displayName,
        ?string $iban,
        ?string $merchantName,
        array $metadata,
        ?string $defaultName = null,
    ): CounterpartyResolutionDto {
        $slug = $this->slugResolver->resolveUnique($userId, $displayName);
        $created = CounterpartyDefaultName::mark($metadata, $defaultName);

        // slug and type stay plaintext because they are the matching and
        // routing keys; only display_name/merchant_name/iban go through the
        // codec.
        $row = Counterparty::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'slug' => $slug,
            ],
            $this->codec->encryptAttrs('counterparties', [
                'type' => $type,
                'display_name' => $displayName,
                'iban' => $iban,
                'merchant_name' => $merchantName,
                'metadata' => $created === [] ? null : $created,
            ], $userId, ($this->session)()),
        );

        // The id of a row this call minted is read back by the (user_id, slug)
        // UNIQUE, never carried out of firstOrCreate(): it ends in insertGetId(),
        // lastInsertId() is per connection, and the badge listener writes a
        // `cache` row from inside this INSERT's own event.
        $counterpartyId = $row->wasRecentlyCreated
            ? IdReadBack::of($this->db->connection(), 'counterparties', ['user_id' => $userId, 'slug' => $slug])
            : $row->id;

        // Plaintext on purpose: OpLogWriter encrypts sensitive columns itself
        // under the GDK epoch, and the backfiller decrypts before handing them
        // over. Passing the stored ciphertext would encrypt it twice and the
        // peer would never read it back.
        if ($row->wasRecentlyCreated) {
            $this->events->dispatch(new EntityMutated(
                table: 'counterparties',
                pk: $counterpartyId,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $userId,
                    'slug' => $slug,
                    'type' => $type,
                    'display_name' => $displayName,
                    'iban' => $iban,
                    'merchant_name' => $merchantName,
                    // The ignored flag and the subcategory live in here and
                    // nowhere else, so omitting it landed the counterparty on
                    // the peer with both of them blank.
                    'metadata' => $created === [] ? null : $created,
                ],
            ));
        } else {
            $this->refreshStored($row, $userId, $type, $iban, $merchantName, $metadata);
        }

        $this->events->dispatch(new CounterpartyResolved(
            counterpartyId: $counterpartyId,
            userId: $userId,
            type: $type,
        ));

        return new CounterpartyResolutionDto(
            type: $type,
            displayName: $displayName,
            slug: $slug,
            iban: $iban,
            merchantName: $merchantName,
            metadata: $metadata,
            counterpartyId: $counterpartyId,
        );
    }

    // A row minted by an earlier, thinner pass keeps what that pass knew: an
    // `unknown` CounterpartyTriageQueue then holds forever, and a NULL
    // merchant_name the garbage collector prunes on. display_name is left
    // alone — the slug derives from it, so a different name is a different row.
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function refreshStored(
        Counterparty $row,
        int $userId,
        string $type,
        ?string $iban,
        ?string $merchantName,
        array $metadata,
    ): void {
        $session = ($this->session)();
        $stored = $this->codec->decryptRow('counterparties', [
            'iban' => $row->iban,
            'merchant_name' => $row->merchant_name,
        ], $userId, $session);

        $changed = [];
        if ($type !== CounterpartyType::Unknown->value && $type !== $row->type) {
            $changed['type'] = $type;
        }
        // A null here is this pass knowing less about the counterparty, not
        // the stored value being wrong, so it never clears one.
        if ($iban !== null && $iban !== $stored['iban']) {
            $changed['iban'] = $iban;
        }
        if ($merchantName !== null && $merchantName !== $stored['merchant_name']) {
            $changed['merchant_name'] = $merchantName;
        }
        $storedMetadata = is_array($row->metadata) ? $row->metadata : [];
        $merged = CounterpartyDefaultName::carriedOver($storedMetadata, $metadata);
        if ($merged !== [] && $merged !== $row->metadata) {
            $changed['metadata'] = $merged;
        }

        if ($changed === []) {
            return;
        }

        $row->forceFill($this->codec->encryptAttrs('counterparties', $changed, $userId, $session));
        $row->save();

        // Plaintext, for the same reason the create branch above passes
        // plaintext: OpLogWriter seals it again under the GDK epoch.
        $this->events->dispatch(new EntityMutated(
            table: 'counterparties',
            pk: $row->id,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: $changed,
        ));
    }

    private function normaliseIban(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $compact = CounterpartyKey::compactIban($iban);

        return $compact === '' ? null : $compact;
    }

    private function haystack(CanonicalTransaction $tx): string
    {
        $description = $tx->description ?? '';
        $name = $tx->counterpartyName ?? '';

        return trim($description.' '.$name);
    }

    // The name, and the app's own word for it when the name came from nowhere
    // but here. Only the generic label is the app's: the other four arms are
    // the file's own words or the corpus's, which stay as they were written.
    /**
     * @return array{0: string, 1: string|null}
     */
    private function governmentDisplayName(ClassificationRule $rule, CanonicalTransaction $tx): array
    {
        $name = $tx->counterpartyName;
        $trimmedName = is_string($name) ? trim($name) : '';
        $isRegex = $this->matcher->isRegex($rule->pattern);

        // A literal pattern found verbatim in the name keeps the fuller name
        // ("GEMEENTE UTRECHT" over "Gemeente"). A regex pattern can't be
        // substring-checked and is not human copy, so it falls back to a
        // generic label rather than leaking PCRE syntax into the UI and slug.
        return match (true) {
            $trimmedName !== '' && ! $isRegex && stripos($trimmedName, $rule->pattern) !== false => [$trimmedName, null],
            $rule->name !== null => [$rule->name, null],
            $trimmedName !== '' => [$trimmedName, null],
            $isRegex => [
                CounterpartyDefaultName::storedName(CounterpartyDefaultName::GOVERNMENT),
                CounterpartyDefaultName::GOVERNMENT,
            ],
            default => [ucfirst(strtolower($rule->pattern)), null],
        };
    }

    private function looksLikePersonalName(string $name): bool
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return false;
        }

        $tokens = preg_split('/\s+/', strtoupper($trimmed));
        if ($tokens === false || count($tokens) > 4) {
            return false;
        }

        return ! $this->containsMerchantMarker($tokens);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function containsMerchantMarker(array $tokens): bool
    {
        return array_any($tokens, fn (string $token): bool => in_array(trim($token, ','), self::MERCHANT_NAME_MARKERS, true));
    }
}
