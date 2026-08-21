<?php

declare(strict_types=1);

namespace App\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\UseItem;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @link ../../../.docs/architecture/module-boundaries.md
 *
 * @implements Rule<UseItem>
 */
final class BoundaryRule implements Rule
{
    // Prefixes under Modules\<Y>\ that are part of module Y's public
    // surface; any import whose tail begins with one of these is allowed.
    private const PUBLIC_PREFIXES = [
        'Public',
        'Models',
    ];

    public function getNodeType(): string
    {
        return UseItem::class;
    }

    /**
     * @param  UseItem  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $importerModule = $this->extractModuleFromNamespace($scope->getNamespace())
            ?? $this->extractModuleFromPath($scope->getFile());

        if ($importerModule === null) {
            return [];
        }

        $imported = $node->name->toString();
        $importedModule = $this->extractModuleFromFqn($imported);

        if ($importedModule === null
            || $importedModule === $importerModule
            || ! $this->violatesBoundary($imported, $importedModule)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Cross-module Internal/Models import forbidden: %s cannot use %s',
                $importerModule,
                $imported,
            ))->identifier('beatrax.boundary')->build(),
        ];
    }

    private function extractModuleFromNamespace(?string $namespace): ?string
    {
        if ($namespace === null) {
            return null;
        }

        if (preg_match('~^Modules\\\\([A-Z][A-Za-z0-9]*)(?:\\\\|$)~', $namespace, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function extractModuleFromPath(string $path): ?string
    {
        $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        if (preg_match('~/Modules/([A-Z][A-Za-z0-9]*)/~', $normalized, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function extractModuleFromFqn(string $fqn): ?string
    {
        if (preg_match('~^Modules\\\\([A-Z][A-Za-z0-9]*)\\\\~', $fqn, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    // An allow-list, so a private directory added later is covered without
    // touching this rule.
    private function violatesBoundary(string $fqn, string $targetModule): bool
    {
        $prefix = 'Modules\\'.$targetModule.'\\';
        $tail = substr($fqn, strlen($prefix));

        foreach (self::PUBLIC_PREFIXES as $segment) {
            if ($tail === $segment || str_starts_with($tail, $segment.'\\')) {
                return false;
            }
        }

        return true;
    }
}
