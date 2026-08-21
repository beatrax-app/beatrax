<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use Iban\Validation\Validator as IbanValidator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\ClassificationRule;
use Modules\Community\Public\Services\ClassificationRuleProvider;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Services\UserCountry;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Dto\CounterpartyResolutionDto;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Events\CounterpartyResolved;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md
 */
final class CounterpartyResolverService implements CounterpartyResolver
{
    private const MERCHANT_NAME_MARKERS = [
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

    private const PERSONAL_TRANSACTION_TYPES = [
        'transfer_in',
        'transfer_out',
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ResolvesKnownCounterpartyIban $aliasBridge,
        private readonly MerchantNameResolver $merchantResolver,
        private readonly Dispatcher $events,
        private readonly IbanValidator $ibanValidator,
        private readonly ClassificationRuleProvider $ruleProvider,
        private readonly CorpusPatternMatcher $matcher,
        private readonly SensitiveColumnCodec $codec,
        // A factory, not the session: resolving a session builds the encrypter,
        // and Artisan constructs this class merely to list a console command.
        private readonly SessionFactory $session,
        private readonly CounterpartySlugResolver $slugResolver,
        private readonly UserCountry $countries,
    ) {}

    /** @var array<int, string> */
    private array $regionByUser = [];

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

        return $this->upsert(
            userId: $userId,
            type: CounterpartyType::Merchant->value,
            displayName: $merchantName,
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

    // Empty when the reader has named no country, which loads every region as
    // before rather than classifying nothing. Memoised: this runs per
    // transaction and the answer cannot change inside one import.
    private function regionFor(int $userId): string
    {
        return $this->regionByUser[$userId] ??= $this->countries->current($userId);
    }

    private function resolveGovernment(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        return $this->resolveByRules(
            $tx,
            $userId,
            $this->ruleProvider->governmentRules($this->regionFor($userId)),
            CounterpartyType::Government->value,
            fn (ClassificationRule $rule): array => [
                $this->governmentDisplayName($rule, $tx),
                ['matched_keyword' => $rule->pattern],
            ],
        );
    }

    private function resolveBankFee(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        return $this->resolveByRules(
            $tx,
            $userId,
            $this->ruleProvider->bankFeeRules($this->regionFor($userId)),
            CounterpartyType::Bank->value,
            fn (ClassificationRule $rule): array => [
                $rule->name ?? 'Bank fee',
                ['subcategory' => 'fee', 'matched_keyword' => $rule->pattern],
            ],
        );
    }

    /**
     * @param  list<ClassificationRule>  $rules
     * @param  callable(ClassificationRule): array{0: string, 1: array<string, mixed>}  $build
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

            [$displayName, $metadata] = $build($rule);

            return $this->upsert(
                userId: $userId,
                type: $type,
                displayName: $displayName,
                iban: $this->normaliseIban($tx->counterpartyIban),
                merchantName: null,
                metadata: $metadata,
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

        if ($name !== null && trim($name) !== '') {
            $displayName = trim($name);
        } elseif ($iban !== null) {
            $displayName = $iban;
        } else {
            $displayName = 'Unknown';
        }

        return $this->upsert(
            userId: $userId,
            type: CounterpartyType::Unknown->value,
            displayName: $displayName,
            iban: $iban,
            merchantName: null,
            metadata: [],
        );
    }

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
    ): CounterpartyResolutionDto {
        $slug = $this->slugResolver->resolveUnique($userId, $displayName);

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
                'metadata' => $metadata === [] ? null : $metadata,
            ], $userId, ($this->session)()),
        );

        // Plaintext on purpose: OpLogWriter encrypts sensitive columns itself
        // under the GDK epoch, and the backfiller decrypts before handing them
        // over. Passing the stored ciphertext would encrypt it twice and the
        // peer would never read it back.
        if ($row->wasRecentlyCreated) {
            $this->events->dispatch(new EntityMutated(
                table: 'counterparties',
                pk: $row->id,
                userId: $userId,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $userId,
                    'slug' => $slug,
                    'type' => $type,
                    'display_name' => $displayName,
                    'iban' => $iban,
                    'merchant_name' => $merchantName,
                    // website and logo_url live in here and nowhere else, so
                    // omitting it landed the counterparty on the peer with
                    // both fields blank.
                    'metadata' => $metadata === [] ? null : $metadata,
                ],
            ));
        }

        $this->events->dispatch(new CounterpartyResolved(
            counterpartyId: $row->id,
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
            counterpartyId: $row->id,
        );
    }

    private function normaliseIban(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $compact = preg_replace('/\s+/', '', $iban) ?? '';
        if ($compact === '') {
            return null;
        }

        return strtoupper($compact);
    }

    private function haystack(CanonicalTransaction $tx): string
    {
        $description = $tx->description ?? '';
        $name = $tx->counterpartyName ?? '';

        return trim($description.' '.$name);
    }

    private function governmentDisplayName(ClassificationRule $rule, CanonicalTransaction $tx): string
    {
        $name = $tx->counterpartyName;
        $trimmedName = is_string($name) ? trim($name) : '';
        $isRegex = $this->matcher->isRegex($rule->pattern);

        // A literal pattern found verbatim in the name keeps the fuller name
        // ("GEMEENTE UTRECHT" over "Gemeente"). A regex pattern can't be
        // substring-checked and is not human copy, so it falls back to a
        // generic label rather than leaking PCRE syntax into the UI and slug.
        return match (true) {
            $trimmedName !== '' && ! $isRegex && stripos($trimmedName, $rule->pattern) !== false => $trimmedName,
            $rule->name !== null => $rule->name,
            $trimmedName !== '' => $trimmedName,
            $isRegex => 'Government',
            default => ucfirst(strtolower($rule->pattern)),
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
        foreach ($tokens as $token) {
            if (in_array(trim($token, ','), self::MERCHANT_NAME_MARKERS, true)) {
                return true;
            }
        }

        return false;
    }
}
