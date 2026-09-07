<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Boot;

use SimpleXMLElement;

// codesign answers with a plist and has no JSON option, so this is the one
// place that reads one. Pure, because what an entitlement plist means is not
// a property of the machine it was read on.
final class EntitlementsPlist
{
    // An <array> or <dict> value flattens to its text: nothing here reads a
    // list, every entitlement this product judges is a boolean, and a nested
    // parser would be reach nothing needs.
    /** @return array<string, mixed> */
    public static function parse(string $xml): array
    {
        $document = @simplexml_load_string($xml);

        if (! $document instanceof SimpleXMLElement || ! isset($document->dict)) {
            return [];
        }

        $entitlements = [];
        $key = null;

        foreach ($document->dict->children() as $node) {
            if ($node->getName() === 'key') {
                $key = (string) $node;

                continue;
            }

            if ($key === null) {
                continue;
            }

            $entitlements[$key] = match ($node->getName()) {
                'true' => true,
                'false' => false,
                default => (string) $node,
            };
            $key = null;
        }

        return $entitlements;
    }
}
