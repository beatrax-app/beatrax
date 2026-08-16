<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Public\Dto\SupportResource;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class SupportResourceProvider
{
    // The file every country falls back to, and the only one that is not a
    // country: brands that bill the same way everywhere live here.
    private const SHARED = 'international';

    /** @var array<string, array<string, SupportResource>>|null country => (word key => resource) */
    private ?array $byCountry = null;

    public function __construct(
        private readonly CorpusYamlReader $reader,
    ) {}

    /**
     * @param  string|null  $country  ISO 3166-1 alpha-2, lowercase; null searches every country
     */
    public function forCounterparty(string $name, string $type, ?string $country = null): ?SupportResource
    {
        $needle = $this->words($name);
        if ($needle === []) {
            return null;
        }

        // Own country first, then the shared file, then everyone else.
        // Sanitas is a Swiss health insurer AND a Spanish provider, and the
        // alphabetically-later file used to win for every user. A caller with
        // no country still searches everything, as before.
        foreach ($this->searchOrder($country) as $bucket) {
            $best = $this->bestIn($bucket, $needle, $type);
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, SupportResource>>
     */
    private function searchOrder(?string $country): array
    {
        $byCountry = $this->load();
        $code = $country === null ? null : mb_strtolower(trim($country));

        $order = [];
        if ($code !== null && isset($byCountry[$code])) {
            $order[] = $byCountry[$code];
        }
        if (isset($byCountry[self::SHARED])) {
            $order[] = $byCountry[self::SHARED];
        }

        foreach ($byCountry as $bucketCode => $bucket) {
            if ($bucketCode !== $code && $bucketCode !== self::SHARED) {
                $order[] = $bucket;
            }
        }

        return $order;
    }

    // Longest word-prefix match wins, so "Albert Heijn Premium" beats the
    // plain "Albert Heijn" row rather than whichever the loop reached first.
    /**
     * @param  array<string, SupportResource>  $bucket
     * @param  list<string>  $needle
     */
    private function bestIn(array $bucket, array $needle, string $type): ?SupportResource
    {
        $best = null;
        $bestLength = 0;

        foreach ($bucket as $key => $resource) {
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
     * @return array<string, array<string, SupportResource>>
     */
    private function load(): array
    {
        if ($this->byCountry !== null) {
            return $this->byCountry;
        }

        $dir = $this->reader->resolve('community.corpus.root', 'resources/corpus').'/support';

        return $this->byCountry = $this->buildMap($dir);
    }

    /**
     * @return array<string, array<string, SupportResource>>
     */
    private function buildMap(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.yaml');
        if ($files === false) {
            return [];
        }
        sort($files);

        $map = [];
        foreach ($files as $file) {
            // Country comes from the filename, the same convention the
            // merchant and government corpora already use.
            $code = mb_strtolower(basename($file, '.yaml'));
            foreach ($this->reader->readEntries($file) as $raw) {
                $this->addResource($map, $code, $raw);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, SupportResource>>  $map
     * @param  array<int|string, mixed>  $raw
     */
    private function addResource(array &$map, string $country, array $raw): void
    {
        $resource = $this->build($raw);
        if ($resource === null) {
            return;
        }

        $key = implode(' ', $this->words($resource->name));
        if ($key !== '') {
            $map[$country][$key] = $resource;
        }
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
            cancelUrl: self::url($raw, 'cancel_url'),
            supportUrl: self::url($raw, 'support_url'),
            cheaperUrl: self::url($raw, 'cheaper_url'),
            helpUrl: self::url($raw, 'help_url'),
            applyUrl: self::url($raw, 'apply_url'),
            rightsUrl: self::url($raw, 'rights_url'),
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
     * @param  array<int|string, mixed>  $array
     */
    private static function url(array $array, string $key): ?string
    {
        // Unlike str(), only an http(s) value passes — a malformed or
        // non-http(s) corpus value (e.g. a `javascript:` scheme) can never
        // reach a consumer as a clickable href.
        $value = self::str($array, $key);
        if ($value === null) {
            return null;
        }

        return str_starts_with($value, 'https://') || str_starts_with($value, 'http://') ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function words(string $name): array
    {
        // Brand words (Premium / Plus) are intentionally NOT stripped below —
        // they distinguish a subscription tier from the base brand (e.g.
        // "Albert Heijn Premium" vs plain "Albert Heijn").
        $lowered = mb_strtolower(trim($name));
        $stripped = preg_replace('/\b(b\.?v\.?|n\.?v\.?|inc|ltd|gmbh|ab|sa|plc)\b/u', ' ', $lowered) ?? $lowered;
        preg_match_all('/[\p{L}\p{N}]+/u', $stripped, $matches);

        return $matches[0];
    }
}
