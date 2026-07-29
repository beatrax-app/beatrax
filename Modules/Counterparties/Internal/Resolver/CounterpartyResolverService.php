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
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Dto\CounterpartyResolutionDto;
use Modules\Counterparties\Public\Events\CounterpartyResolved;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// The default CounterpartyResolver implementation — the seven-step
// precedence chain (self-account, known-IBAN bridge, merchant, personal,
// government, bank-fee, unknown) is documented in full at the @link below.
/**
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
final class CounterpartyResolverService implements CounterpartyResolver
{
    // Single-token markers that disqualify a name from the personal-IBAN
    // heuristic (step 4, which only fires after merchant resolution has
    // already had a chance to match) — these are far more likely to
    // signal a small-business merchant than a personal P2P transfer.
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

    // Restricts the personal-IBAN heuristic to cross-account P2P-shaped
    // rows, so a merchant transaction carrying a Dutch IBAN is never
    // mis-classified as personal.
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
        // A factory, not the session itself: resolving a session builds the
        // encrypter, and this class is reachable from a console command that
        // Artisan constructs merely to list it.
        private readonly SessionFactory $session,
    ) {}

    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto
    {
        $userId = $user->id;

        // The order is the classification rule, not an implementation detail:
        // the first strategy to recognise the transaction wins, and a later
        // one must never override an earlier. A list so the ordering is one
        // readable thing rather than a sequence of early returns.
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

        // Nothing recognised it, which is a resolution in itself rather than
        // a failure — the transaction still gets a counterparty.
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
            type: 'self_account',
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

        // The bridge contract returns the user's own Account (built for
        // Chains routing); read the legal-entity notes directly instead,
        // since they are far more informative for display than the
        // user's own account name.
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
            type: 'bank',
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
            type: 'merchant',
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
            type: 'personal',
            // displayName is the trimmed counterparty name; the slug
            // derives from the displayName only (no IBAN suffix) — the
            // privacy default enforced by PrivacyDefaultsTest.
            displayName: trim($name),
            iban: $iban,
            merchantName: null,
            metadata: [],
        );
    }

    // Any structurally valid SEPA IBAN (mod-97 + BBAN length, not just Dutch)
    // paired with a name that clears the small-business marker guard counts as
    // a personal P2P transfer.
    private function isPersonalTransfer(CanonicalTransaction $tx, string $iban, string $name): bool
    {
        return in_array($tx->type, self::PERSONAL_TRANSACTION_TYPES, true)
            && $this->ibanValidator->validate($iban)
            && trim($name) !== ''
            && $this->looksLikePersonalName($name);
    }

    private function resolveGovernment(CanonicalTransaction $tx, int $userId): ?CounterpartyResolutionDto
    {
        return $this->resolveByRules(
            $tx,
            $userId,
            $this->ruleProvider->governmentRules(),
            'government',
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
            $this->ruleProvider->bankFeeRules(),
            'bank',
            fn (ClassificationRule $rule): array => [
                $rule->name ?? 'Bank fee',
                ['subcategory' => 'fee', 'matched_keyword' => $rule->pattern],
            ],
        );
    }

    // Shared engine for the YAML-rule classification steps (government,
    // bank-fee); $build supplies the step-specific display name and
    // metadata for the matched rule.
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

        // Nothing to surface in the Triage queue for a transaction with
        // no name, IBAN, or description; null leaves it without a
        // counterparty_id, but the writer layer still records the row.
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
            type: 'unknown',
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
        $baseSlug = $this->slugify($displayName);
        $slug = $this->resolveSlugForUpsert($userId, $baseSlug, $displayName);

        // display_name/merchant_name/iban route through the codec (a
        // no-op when encryption is disabled); slug/type stay plaintext as
        // matching/routing keys. firstOrCreate() means an existing row's
        // stored ciphertext is left untouched.
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

    // The stored display_name is decrypted before the identity comparison
    // (see the slug-strategy note at the @link above) so an already-
    // resolved counterparty is never wrongly treated as "taken by a
    // different name" just because the column is now ciphertext.
    private function resolveSlugForUpsert(
        int $userId,
        string $baseSlug,
        string $displayName,
    ): string {
        if ($this->slugIsFreeFor($userId, $baseSlug, $displayName)) {
            return $baseSlug;
        }

        $suffix = 2;
        while (! $this->slugIsFreeFor($userId, $baseSlug.'-'.$suffix, $displayName)) {
            $suffix++;
        }

        return $baseSlug.'-'.$suffix;
    }

    // Free means unused, or already held by this same counterparty. The
    // stored name is decrypted before comparing so a row whose column is now
    // ciphertext is not mistaken for a different holder. The base slug and
    // every numbered candidate ask this one question.
    private function slugIsFreeFor(int $userId, string $slug, string $displayName): bool
    {
        $existing = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->value('display_name');

        return $existing === null
            || (is_string($existing) && $this->decryptDisplayName($existing, $userId) === $displayName);
    }

    // Never throws — an undecryptable value falls back to the raw
    // ciphertext string, which simply fails the identity comparison above
    // and falls through to slug suffixing.
    private function decryptDisplayName(string $stored, int $userId): string
    {
        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, ($this->session)())['value'];
    }

    // Strips punctuation/accents to a lowercase ASCII approximation and
    // collapses whitespace/underscores into single `-` separators; bounded
    // to the column's 128-char UNIQUE-index width.
    private function slugify(string $value): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = strtolower($ascii);
        $cleaned = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';
        $trimmed = trim($cleaned, '-');

        if ($trimmed === '') {
            return 'counterparty';
        }

        return substr($trimmed, 0, 128);
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
        // When a literal pattern appears verbatim in the name (e.g.
        // "GEMEENTE UTRECHT"), surface the fuller name so the city/office
        // is preserved; regex patterns can't be substring-checked, so
        // they fall through to the rule's canonical name.
        $name = $tx->counterpartyName;
        $trimmedName = is_string($name) ? trim($name) : '';
        $isRegex = $this->matcher->isRegex($rule->pattern);

        // The last arm is for a name-less rule that matched only the
        // description: title-case a literal pattern, but a regex body (e.g.
        // `RUNDFUNK|ARD ZDF`) is not human copy, so use a generic label rather
        // than leak PCRE syntax into the UI and the slug.
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

    // A marker token is what separates "J VAN DER BERG" from "BERG BV": a
    // personal name carries none of them.
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
