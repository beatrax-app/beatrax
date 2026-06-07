<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Public\Dto\SupportResource;

/**
 * Loads the bundled support-resource corpus (`resources/corpus/support/*.yaml`)
 * and looks resources up by counterparty name + type for the profile page.
 *
 * Matching is name-based and tolerant of the legal-entity noise real
 * counterparty names carry ("Spotify AB", "Netflix International BV"): both the
 * resource name and the queried name are reduced to a lower-cased WORD LIST
 * (legal suffixes like BV/NV/Inc/AB dropped, punctuation split out), and a
 * resource matches when its words are a leading prefix of the counterparty's
 * words. Word-level (not substring) prefixing is deliberate: it lets "Netflix"
 * match "Netflix International BV" without letting "Apple" match "Applebee's",
 * and keeps "Albert Heijn Premium" from matching a plain "Albert Heijn" grocery
 * charge. The longest (most specific) matching resource wins. Read once and
 * memoised.
 */
final class SupportResourceProvider
{
    /** @var array<string, SupportResource>|null space-joined word key => resource */
    private ?array $byName = null;

    public function __construct(
        private readonly CorpusYamlReader $reader,
    ) {}

    public function forCounterparty(string $name, string $type): ?SupportResource
    {
        $needle = $this->words($name);
        if ($needle === []) {
            return null;
        }

        $map = $this->load();
        $best = null;
        $bestLength = 0;

        foreach ($map as $key => $resource) {
            if ($resource->type !== $type) {
                continue;
            }
            $resourceWords = explode(' ', $key);
            $length = count($resourceWords);
            if ($length > $bestLength && array_slice($needle, 0, $length) === $resourceWords) {
                $best = $resource;
                $bestLength = $length;
            }
        }

        return $best;
    }

    /**
     * @return array<string, SupportResource>
     */
    private function load(): array
    {
        if ($this->byName !== null) {
            return $this->byName;
        }

        $dir = $this->reader->resolve('community.corpus.root', 'resources/corpus').'/support';
        $map = [];

        if (is_dir($dir)) {
            $files = glob($dir.'/*.yaml');
            if ($files !== false) {
                sort($files);
                foreach ($files as $file) {
                    foreach ($this->reader->readEntries($file) as $raw) {
                        $resource = $this->build($raw);
                        if ($resource !== null) {
                            $key = implode(' ', $this->words($resource->name));
                            if ($key !== '') {
                                $map[$key] = $resource;
                            }
                        }
                    }
                }
            }
        }

        return $this->byName = $map;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function build(array $raw): ?SupportResource
    {
        $name = self::str($raw, 'name');
        $type = self::str($raw, 'type');
        if ($name === null || $type === null) {
            return null;
        }

        $email = is_array($raw['cancel_email'] ?? null) ? $raw['cancel_email'] : [];

        return new SupportResource(
            name: $name,
            type: $type,
            cancelUrl: self::str($raw, 'cancel_url'),
            supportUrl: self::str($raw, 'support_url'),
            cheaperUrl: self::str($raw, 'cheaper_url'),
            helpUrl: self::str($raw, 'help_url'),
            applyUrl: self::str($raw, 'apply_url'),
            rightsUrl: self::str($raw, 'rights_url'),
            phone: self::str($raw, 'phone'),
            cancelEmailTo: self::str($email, 'to'),
            cancelEmailSubject: self::str($email, 'subject'),
            cancelEmailBody: self::str($email, 'body'),
            notes: self::str($raw, 'notes'),
        );
    }

    /**
     * @param  array<int|string, mixed>  $array
     */
    private static function str(array $array, string $key): ?string
    {
        $value = $array[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Lower-cased word list with legal-entity suffixes dropped. Brand words
     * (Premium / Plus) are intentionally NOT stripped — they distinguish a
     * subscription tier from the base brand (e.g. "Albert Heijn Premium").
     *
     * @return list<string>
     */
    private function words(string $name): array
    {
        $lowered = mb_strtolower(trim($name));
        $stripped = preg_replace('/\b(b\.?v\.?|n\.?v\.?|inc|ltd|gmbh|ab|sa|plc)\b/u', ' ', $lowered) ?? $lowered;
        preg_match_all('/[\p{L}\p{N}]+/u', $stripped, $matches);

        return $matches[0];
    }
}
