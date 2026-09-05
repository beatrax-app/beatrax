<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Internal\Support\RecipientAddress;
use Modules\Community\Public\Dto\SupportResource;
use Modules\Core\Public\Enums\ExternalUrlRefusal;
use Modules\Core\Public\Support\ExternalUrl;
use Modules\Core\Public\Support\PatternScan;
use Psr\Log\LoggerInterface;

final class SupportResourceProvider
{
    // The file every country falls back to, and the only one that is not a
    // country: brands that bill the same way everywhere live here.
    private const string SHARED = 'international';

    /** @var array<string, array<string, list<SupportResource>>>|null country => (word key => resources) */
    private ?array $byCountry = null;

    public function __construct(
        private readonly CorpusYamlReader $reader,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  string|null  $country  ISO 3166-1 alpha-2, lowercase; null has no country of its own to prefer
     */
    public function forCounterparty(string $name, string $type, ?string $country = null): ?SupportResource
    {
        $needle = self::nameWords($name);
        if ($needle === []) {
            return null;
        }

        $byCountry = $this->load();
        $code = $country === null ? null : mb_strtolower(trim($country));

        // Own country first, then shared, then everyone else: Sanitas is both
        // a Swiss health insurer and a Spanish provider, and the
        // alphabetically-later file used to win for every user.
        $preferred = $code !== null && isset($byCountry[$code])
            ? $this->bestIn($byCountry[$code], $needle, $type)
            : null;

        $preferred ??= isset($byCountry[self::SHARED])
            ? $this->bestIn($byCountry[self::SHARED], $needle, $type)
            : null;

        return $preferred ?? $this->undisputedForeign($byCountry, $code, $needle, $type);
    }

    // The word key a name is filed and matched under. Public because the corpus
    // integrity test asserts on the key this provider actually computes, not on
    // a second normalisation that could drift away from it.
    public static function wordKey(string $name): string
    {
        return implode(' ', self::nameWords($name));
    }

    // Verisure is six national companies with six cancellation lines, and a
    // reader who named no country has nothing here to pick between them. One
    // foreign file answering is an answer; two disagreeing is a coin toss, and
    // the wrong country's cancellation route is worse than none.
    /**
     * @param  array<string, array<string, list<SupportResource>>>  $byCountry
     * @param  list<string>  $needle
     */
    private function undisputedForeign(array $byCountry, ?string $code, array $needle, string $type): ?SupportResource
    {
        $found = null;

        foreach ($byCountry as $bucketCode => $bucket) {
            if ($bucketCode === $code || $bucketCode === self::SHARED) {
                continue;
            }
            $match = $this->bestIn($bucket, $needle, $type);
            if ($match === null) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $match;
        }

        return $found;
    }

    // Longest word-prefix match wins, so "Albert Heijn Premium" beats the
    // plain "Albert Heijn" row rather than whichever the loop reached first.
    /**
     * @param  array<string, list<SupportResource>>  $bucket
     * @param  list<string>  $needle
     */
    private function bestIn(array $bucket, array $needle, string $type): ?SupportResource
    {
        $best = null;
        $bestLength = 0;

        foreach ($bucket as $key => $resources) {
            $resourceWords = explode(' ', $key);
            $length = count($resourceWords);
            if ($length <= $bestLength || array_slice($needle, 0, $length) !== $resourceWords) {
                continue;
            }
            foreach ($resources as $resource) {
                if ($resource->type !== $type) {
                    continue;
                }
                $best = $resource;
                $bestLength = $length;
                break;
            }
        }

        return $best;
    }

    /**
     * @return array<string, array<string, list<SupportResource>>>
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
     * @return array<string, array<string, list<SupportResource>>>
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
            $code = mb_strtolower(basename($file, '.yaml'));
            foreach ($this->reader->readEntries($file) as $raw) {
                $this->addResource($map, $code, $raw);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, list<SupportResource>>>  $map
     * @param  array<int|string, mixed>  $raw
     */
    private function addResource(array &$map, string $country, array $raw): void
    {
        $resource = $this->build($raw);
        if ($resource === null) {
            return;
        }

        // Appended, not assigned: the key is the name alone while a lookup also
        // filters on type, so one country's "Sanitas" merchant entry and its
        // "Sanitas" government entry used to overwrite each other.
        $key = self::wordKey($resource->name);
        if ($key !== '') {
            $map[$country][$key][] = $resource;
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

        $withheld = [];
        $cancel = $this->url($raw, 'cancel_url', $name, $withheld);
        $support = $this->url($raw, 'support_url', $name, $withheld);
        $cheaper = $this->url($raw, 'cheaper_url', $name, $withheld);
        $help = $this->url($raw, 'help_url', $name, $withheld);
        $apply = $this->url($raw, 'apply_url', $name, $withheld);
        $rights = $this->url($raw, 'rights_url', $name, $withheld);

        $email = is_array($raw['cancel_email'] ?? null) ? $raw['cancel_email'] : [];
        $to = self::recipient($email);

        return new SupportResource(
            name: $name,
            type: $type,
            cancelUrl: $cancel,
            supportUrl: $support,
            cheaperUrl: $cheaper,
            helpUrl: $help,
            applyUrl: $apply,
            rightsUrl: $rights,
            phone: self::str($raw, 'phone'),
            cancelEmailTo: $to,
            cancelEmailSubject: $to === null ? null : self::str($email, 'subject'),
            cancelEmailBody: $to === null ? null : self::str($email, 'body'),
            notes: self::str($raw, 'notes'),
            withheld: $withheld,
        );
    }

    /**
     * @param  array<int|string, mixed>  $email
     */
    private static function recipient(array $email): ?string
    {
        // The corpus reached the mailto: guard unvalidated, so a `to` carrying a
        // second address was one typo away from a cancellation mail addressed to
        // a stranger. Subject and body exist only to fill that mail, so they go
        // with it when the address is refused.
        $value = self::str($email, 'to');

        return $value !== null && RecipientAddress::isSingle($value) ? $value : null;
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
     * @param  array<string, ExternalUrlRefusal>  $withheld  corpus field key => refusal, appended to
     */
    private function url(array $array, string $key, string $name, array &$withheld): ?string
    {
        $value = self::str($array, $key);
        if ($value === null) {
            return null;
        }

        $refusal = ExternalUrl::refusalFor($value);
        if ($refusal === null) {
            return $value;
        }

        // Recorded, not dropped: a cancellation route that simply vanishes is
        // indistinguishable from a merchant that never published one, and the
        // reader is the person who would otherwise go looking for it.
        $withheld[$key] = $refusal;
        $this->logger->warning('SupportResourceProvider: withheld a corpus link.', [
            'name' => $name,
            'field' => $key,
            'refusal' => $refusal->value,
        ]);

        return null;
    }

    /**
     * @return list<string>
     */
    private static function nameWords(string $name): array
    {
        // Tier words (Premium / Plus) are deliberately not stripped: they
        // distinguish "Albert Heijn Premium" from plain "Albert Heijn".
        $lowered = mb_strtolower(trim($name));
        $stripped = preg_replace('/\b(b\.?v\.?|n\.?v\.?|inc|ltd|gmbh|ab|sa|plc)\b/u', ' ', $lowered) ?? $lowered;

        return PatternScan::all('/[\p{L}\p{N}]+/u', $stripped)[0];
    }
}
