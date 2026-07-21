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
        private readonly Session $session,
    ) {}

    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto
    {
        $userId = $user->id;

        $selfAccount = $this->resolveSelfAccount($tx, $userId);
        if ($selfAccount !== null) {
            return $selfAccount;
        }

        $bridge = $this->resolveKnownBridge($tx, $user, $userId);
        if ($bridge !== null) {
            return $bridge;
        }

        $merchant = $this->resolveMerchant($tx, $userId);
        if ($merchant !== null) {
            return $merchant;
        }

        $personal = $this->resolvePersonal($tx, $userId);
        if ($personal !== null) {
            return $personal;
        }

        $government = $this->resolveGovernment($tx, $userId);
        if ($government !== null) {
            return $government;
        }

        $bankFee = $this->resolveBankFee($tx, $userId);
        if ($bankFee !== null) {
            return $bankFee;
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
        if (! in_array($tx->type, self::PERSONAL_TRANSACTION_TYPES, true)) {
            return null;
        }

        // Any structurally valid SEPA IBAN (mod-97 + BBAN length, not just
        // Dutch) paired with a name that clears the small-business marker
        // guard below counts as a personal P2P transfer.
        $iban = $this->normaliseIban($tx->counterpartyIban);
        if ($iban === null || ! $this->ibanValidator->validate($iban)) {
            return null;
        }

        $name = $tx->counterpartyName;
        if ($name === null || trim($name) === '') {
            return null;
        }

        if (! $this->looksLikePersonalName($name)) {
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
            ], $userId, $this->session),
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
        $existing = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('slug', $baseSlug)
            ->value('display_name');

        if ($existing === null) {
            return $baseSlug;
        }

        if (is_string($existing) && $this->decryptDisplayName($existing, $userId) === $displayName) {
            return $baseSlug;
        }

        $suffix = 2;
        while (true) {
            $candidate = $baseSlug.'-'.$suffix;
            $candidateExisting = $this->db->connection()
                ->table('counterparties')
                ->where('user_id', $userId)
                ->where('slug', $candidate)
                ->value('display_name');

            if ($candidateExisting === null) {
                return $candidate;
            }

            if (is_string($candidateExisting) && $this->decryptDisplayName($candidateExisting, $userId) === $displayName) {
                return $candidate;
            }

            $suffix++;
        }
    }

    // Never throws — an undecryptable value falls back to the raw
    // ciphertext string, which simply fails the identity comparison above
    // and falls through to slug suffixing.
    private function decryptDisplayName(string $stored, int $userId): string
    {
        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, $this->session)['value'];
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

        if ($trimmedName !== '' && ! $this->matcher->isRegex($rule->pattern) && stripos($trimmedName, $rule->pattern) !== false) {
            return $trimmedName;
        }

        if ($rule->name !== null) {
            return $rule->name;
        }

        if ($trimmedName !== '') {
            return $trimmedName;
        }

        // A name-less rule matched only the description and has no
        // counterparty name to fall back on. Title-case a literal pattern;
        // a regex body (e.g. `RUNDFUNK|ARD ZDF`) is not human copy, so use a
        // generic label rather than leaking PCRE syntax into the UI/slug.
        return $this->matcher->isRegex($rule->pattern)
            ? 'Government'
            : ucfirst(strtolower($rule->pattern));
    }

    private function looksLikePersonalName(string $name): bool
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return false;
        }

        $upper = strtoupper($trimmed);
        $tokens = preg_split('/\s+/', $upper);
        if ($tokens === false || count($tokens) > 4) {
            return false;
        }

        foreach ($tokens as $token) {
            $clean = trim($token, ',');
            if (in_array($clean, self::MERCHANT_NAME_MARKERS, true)) {
                return false;
            }
        }

        return true;
    }
}
