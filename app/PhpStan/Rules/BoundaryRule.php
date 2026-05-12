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
 * Enforces the cross-module boundary inside the `Modules\<Name>\` namespace.
 *
 * From a file at namespace `Modules\X\…`, only imports targeted at another
 * module Y (Y ≠ X) that begin with one of these prefixes are allowed:
 *   - `Modules\Y\Public\…`
 *   - `Modules\Y\Models\…`
 *
 * Anything else under `Modules\Y\` is private to module Y and triggers this
 * rule — Internal, Database, Providers, Resources, Routes,
 * Http\Livewire, etc. Routes and Resources currently contain no PHP
 * classes, but the rule still forbids them so a future module that ships a
 * controller class under `Routes/` does not silently gain a public surface.
 *
 * Files outside `Modules\` are not governed by this rule (facade and helper
 * bans are enforced separately by canvural/larastan-strict-rules).
 *
 * The importer module is detected first via the declared namespace (so the
 * deliberate fixture files under `app/PhpStan/Rules/Fixtures/` exercise the
 * rule with no relocation), and falls back to the filesystem path when the
 * namespace is anonymous.
 *
 * @implements Rule<UseItem>
 */
final class BoundaryRule implements Rule
{
    /**
     * Prefixes (under `Modules\<Y>\`) that are part of module Y's public
     * surface. Any import whose tail begins with one of these is allowed.
     */
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

        if ($importedModule === null || $importedModule === $importerModule) {
            return [];
        }

        if (! $this->violatesBoundary($imported, $importedModule)) {
            return [];
        }

        $error = RuleErrorBuilder::message(sprintf(
            'Cross-module Internal/Models import forbidden: %s cannot use %s',
            $importerModule,
            $imported,
        ))->identifier('diederik.boundary')->build();

        return [$error];
    }

    /**
     * Returns the importer module when the declared namespace is `Modules\<Name>\…`.
     */
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

    /**
     * Returns the importer module when the file path lives under `Modules/<Name>/`.
     */
    private function extractModuleFromPath(string $path): ?string
    {
        $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        if (preg_match('~/Modules/([A-Z][A-Za-z0-9]*)/~', $normalized, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Returns the target module when the FQN matches `Modules\<Name>\…`.
     */
    private function extractModuleFromFqn(string $fqn): ?string
    {
        if (preg_match('~^Modules\\\\([A-Z][A-Za-z0-9]*)\\\\~', $fqn, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The FQN's tail (after `Modules\<Y>\`) decides whether the import is on
     * the module's public surface. Anything not matching the PUBLIC_PREFIXES
     * whitelist is treated as a boundary violation, so the rule's PHPDoc
     * promise of "Public + Models only" stays honest as new private
     * directories (Routes, Resources, Database, …) are added.
     */
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
