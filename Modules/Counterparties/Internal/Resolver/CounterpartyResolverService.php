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

/**
 * Default `CounterpartyResolver` implementation — walks the seven-step
 * precedence chain that maps a CanonicalTransaction onto a canonical
 * Counterparty row.
 *
 * Chain ordering (first match wins):
 *
 *   1. Self-account check — the user's own account IBANs
 *      short-circuit to type=`self_account` and DO NOT materialise a
 *      counterparties row (the profile page routes back to the
 *      account view instead). The DTO carries counterpartyId=null
 *      and slug=`''` so callers can branch on the missing primary
 *      key without inspecting the type field.
 *   2. Known-counterparty-IBAN bridge — institution IBANs (PayPal
 *      Luxembourg, ICS at ABN AMRO) resolve to type=`bank`. The
 *      bridge consults `ResolvesKnownCounterpartyIban`; when the
 *      institution IBAN is registered the resolver also reads the
 *      `notes` column on `known_counterparty_ibans` directly so the
 *      display name reflects the institution's legal entity rather
 *      than the user's own account name. The bridge contract returns
 *      the user's own Account because it was designed for
 *      account-routing in the Chains module; the display-name lookup
 *      via the direct table read sidesteps a contract widening
 *      that the bridge module would otherwise need.
 *   3. Merchant resolution — the description is run through
 *      `MerchantNameResolver` and a hit produces type=`merchant`.
 *      The merchant resolver already walks five sub-steps
 *      (per-user exact / per-user generalised / community exact /
 *      community generalised / null); this resolver simply consumes
 *      its output.
 *   4. Personal-IBAN heuristic — any valid SEPA IBAN paired with a
 *      personal-looking counterparty name appearing on a
 *      transfer_out / transfer_in row resolves to type=`personal`
 *      with the privacy default: the slug is the kebab-cased
 *      display name only; the IBAN IS preserved on the
 *      `iban` column but never echoed into the slug.
 *   5. Government keyword fallback — descriptions or counterparty
 *      names matching one of the government agency rules loaded from
 *      `resources/corpus/government/<country>.yaml` resolve to
 *      type=`government`. Rules support literal substrings and `regex:`
 *      patterns (see CorpusPatternMatcher).
 *   6. Description-keyword bank-fee fallback — descriptions matching one
 *      of the bank-fee rules loaded from
 *      `resources/corpus/bank-fees/<country>.yaml` resolve to type=`bank`
 *      with metadata.subcategory=`fee`.
 *   7. Unresolved — type=`unknown`, the IBAN (when present) is
 *      preserved on the Counterparty row for the Triage queue.
 *
 * Slug strategy: kebab-cased display name, with per-user collision
 * suffixing (`bol`, `bol-2`, `bol-3`, …). The DB-layer UNIQUE on
 * (user_id, slug) plus the suffix-walk in `resolveSlugForUpsert()`
 * guarantees no two rows for the same user share a slug.
 *
 * Cross-user posture: every raw query carries an explicit
 * `where('user_id', $user->id)` filter. The BelongsToUser global
 * scope on the Counterparty model is a secondary guard that only
 * fires under an HTTP-bound request; the resolver runs from import
 * pipeline / queue / console contexts where the scope is silent and
 * the explicit filter is load-bearing.
 *
 * Idempotency: every non-`self_account` branch upserts via
 * `firstOrCreate` keyed on (user_id, slug). A second resolution of
 * the same transaction returns the same counterpartyId without
 * issuing an INSERT.
 *
 * Event emission: every successful upsert dispatches
 * `CounterpartyResolved` with (counterpartyId, userId, type). The
 * v1.0.0 cut ships zero listeners; future audit / merge /
 * notification surfaces subscribe without a resolver rewrite.
 */
final class CounterpartyResolverService implements CounterpartyResolver
{
    /**
     * Single-token markers that disqualify a counterparty name from
     * the personal-IBAN heuristic. The list is short by design: the
     * heuristic is a catch-all that only fires after the merchant
     * resolver (step 3) has already had a chance to match. Anything
     * carrying one of these legal-entity markers is far more likely
     * to be a small-business merchant than a personal P2P transfer.
     */
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

    /**
     * Transaction types that the personal-IBAN heuristic accepts.
     * The heuristic only fires on cross-account P2P-shaped rows so a
     * merchant transaction that happens to carry a Dutch IBAN does
     * not get mis-classified as personal.
     */
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

        // Step 1: self-account check. Short-circuits before any upsert.
        $selfAccount = $this->resolveSelfAccount($tx, $userId);
        if ($selfAccount !== null) {
            return $selfAccount;
        }

        // Step 2: known-counterparty-IBAN bridge (PayPal LU / ICS at ABN AMRO).
        $bridge = $this->resolveKnownBridge($tx, $user, $userId);
        if ($bridge !== null) {
            return $bridge;
        }

        // Step 3: merchant resolution via MerchantNameResolver.
        $merchant = $this->resolveMerchant($tx, $userId);
        if ($merchant !== null) {
            return $merchant;
        }

        // Step 4: personal-IBAN heuristic with privacy default.
        $personal = $this->resolvePersonal($tx, $userId);
        if ($personal !== null) {
            return $personal;
        }

        // Step 5: government keyword fallback.
        $government = $this->resolveGovernment($tx, $userId);
        if ($government !== null) {
            return $government;
        }

        // Step 6: bank-fee keyword fallback.
        $bankFee = $this->resolveBankFee($tx, $userId);
        if ($bankFee !== null) {
            return $bankFee;
        }

        // Step 7: unresolved → type=unknown, IBAN preserved for triage.
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

        // The bridge contract returns the user's own Account because it
        // was originally designed for cross-account chain routing. For
        // counterparty display purposes the legal-entity notes on the
        // known_counterparty_ibans row are far more informative than
        // the user's account name, so we read them directly here.
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

        // Any structurally valid SEPA/IBAN account (mod-97 checksum + the
        // country's BBAN length, via jschaedl/iban-validation) paired with a
        // personal-looking name counts as a personal P2P transfer — not just
        // Dutch IBANs. The personal-name guard below keeps small-business
        // counterparties from being mis-typed.
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

    /**
     * Shared engine for the YAML-rule classification steps (government,
     * bank-fee): match the transaction's haystack against each rule in
     * order and upsert the first hit. The $build callback supplies the
     * step-specific display name and metadata for the matched rule.
     *
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

        // A transaction that carries nothing at all — no counterparty
        // name, no counterparty IBAN, and no description — is
        // pathological and has nothing to surface in the Triage
        // queue. Returning null leaves it without a counterparty_id;
        // the writer layer still records the underlying row.
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
     * Upserts a Counterparty row keyed on (user_id, slug); fires the
     * `CounterpartyResolved` event on every call. Idempotency is the
     * contract — calling resolve() twice on the same transaction
     * returns the same counterpartyId without issuing a second INSERT.
     *
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

        // CRYPT-01 (D-02b): display_name/merchant_name/iban are encrypted at
        // this creation-time direct write via the Sync Public
        // SensitiveColumnCodec — pass-through when encryption is not
        // enabled for this user. counterparty_normalized/slug/type are
        // NEVER passed through the codec (matching/routing keys stay
        // plaintext, per D-02b). Only fires on a genuine INSERT —
        // firstOrCreate() leaves an already-existing row's stored
        // ciphertext untouched.
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

    /**
     * Returns the slug to use for the upsert.
     *
     * When an existing row already owns (user_id, base_slug) AND
     * matches the same display_name (per-user-canonical), the
     * existing slug is reused — that is the idempotency case. When
     * the base slug is taken by a row with a different display_name,
     * a numeric suffix is appended (`bol-2`, `bol-3`, …) until a free
     * slot is found. The walk is bounded; per-user UNIQUE on
     * (user_id, slug) keeps the suffix space finite in practice.
     *
     * [Rule 1] Once display_name is encrypted at rest (CRYPT-01), the
     * stored value read off `counterparties.display_name` is ciphertext
     * and can never `===` the plaintext `$displayName` being resolved —
     * a naive comparison would treat every already-resolved counterparty
     * as "taken by a different name" on every re-import, silently
     * fragmenting one merchant into `bol`, `bol-2`, `bol-3`, … forever.
     * The stored value is decrypted (rotation-safe, try-every-epoch; a
     * pass-through no-op when encryption is not enabled) before the
     * comparison.
     */
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

    /**
     * Decrypts a stored `counterparties.display_name` value for the
     * idempotency comparison above. Never throws — a tampered/undecryptable
     * value falls back to the raw stored (ciphertext) string, which simply
     * fails the `===` comparison and falls through to slug suffixing
     * (safe: it can never falsely collide with a plaintext $displayName).
     */
    private function decryptDisplayName(string $stored, int $userId): string
    {
        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, $this->session)['value'];
    }

    /**
     * Builds a kebab-case slug from a display name. The character
     * class strips punctuation and accents to a lowercase ASCII
     * approximation; whitespace and underscores collapse into single
     * `-` separators. Result is bounded to the column's 128-char
     * UNIQUE-index width.
     */
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

    /**
     * Normalises an IBAN to its bank-portable form: uppercased,
     * whitespace stripped. Returns null on empty input so the
     * caller can short-circuit cleanly.
     */
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
        // When a literal pattern appears verbatim in the counterparty name
        // (e.g. "GEMEENTE UTRECHT" or "BELASTINGDIENST APELDOORN"), surface
        // the fuller name so the city/office is preserved. Regex patterns
        // can't be substring-checked, so they fall through to the rule's
        // canonical name.
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
