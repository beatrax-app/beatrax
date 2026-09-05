<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionClass;
use Throwable;

// Whether `SomeClass::someMember` names something that exists. Two guards ask
// it — one of prose in .docs, one of comments in the code — and the answer has
// to come from reflection either way, so it has one home.
final class FirstPartySymbols
{
    /**
     * Short class name to every first-party FQCN that answers to it. Two
     * modules may legitimately both define a `Handler`, so a mention is
     * satisfied by any of them — this asks whether the symbol exists, not
     * which one.
     *
     * @return array<string, list<class-string>>
     */
    public static function classes(): array
    {
        $map = [];

        foreach (self::phpFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
                continue;
            }
            if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $cls) !== 1) {
                continue;
            }

            /** @var class-string $fqcn */
            $fqcn = trim($ns[1]).'\\'.$cls[1];
            $map[$cls[1]][] = $fqcn;
        }

        return $map;
    }

    /**
     * Reflection rather than a grep for `function <name>`, because a member is
     * just as real when it arrives from a parent or a trait — asking the class
     * itself is the only way to tell an inherited member from an absent one.
     *
     * @param  list<class-string>  $candidates
     */
    public static function hasMember(array $candidates, string $member): bool
    {
        foreach ($candidates as $fqcn) {
            // A class whose parent ships only under mobile-app/vendor cannot be
            // loaded from this root, and autoloading it throws rather than
            // answering false. Unverifiable here is not the same as absent, so
            // it is skipped rather than reported.
            try {
                if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn) && ! enum_exists($fqcn)) {
                    continue;
                }

                $reflection = new ReflectionClass($fqcn);
            } catch (Throwable) {
                return true;
            }

            if ($reflection->hasMethod($member) || $reflection->hasConstant($member) || $reflection->hasProperty($member)) {
                return true;
            }

            // An Eloquent model answers to its builder through __callStatic, so
            // naming updateOrCreate() or where() describes something that
            // genuinely works; reflection alone would call it absent.
            if ($reflection->isSubclassOf(Model::class) && self::builderAnswers($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole tree rather than the two roots this walk used to open. A narrow
     * walk here does not miss an offender, it invents one: a class declared
     * under database/ or scripts/ read as absent, and the comment naming it
     * reported as naming nothing.
     *
     * @return list<string>
     */
    public static function phpFiles(): array
    {
        return array_values(array_filter(
            RepoTree::files(RepoTree::EVERY_PHP_FILE),
            static fn (string $path): bool => ! str_contains($path, '/Database/Migrations/')
                && ! str_contains($path, '/migrations/'),
        ));
    }

    private static function builderAnswers(string $member): bool
    {
        foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
            if (method_exists($builder, $member)) {
                return true;
            }
        }

        return false;
    }
}
