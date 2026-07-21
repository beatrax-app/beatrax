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
