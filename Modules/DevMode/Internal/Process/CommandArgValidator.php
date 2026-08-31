<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use Modules\DevMode\Public\Dto\CommandSpec;

// The declared ArgSpec rules are the allow-list's own statement of what the
// child may receive, and the last guard before escapeshellarg hands the value
// to a shell. One seam for all four spawn entry points, so a surface cannot
// be the one that quietly skipped them.
final readonly class CommandArgValidator
{
    public function __construct(
        private ValidatorFactory $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws ValidationException
     */
    public function assertValid(CommandSpec $spec, array $args): void
    {
        if ($spec->argsSchema === []) {
            return;
        }

        $rules = [];
        foreach ($spec->argsSchema as $argSpec) {
            $rules['args.'.$argSpec->name] = $argSpec->rules;
        }

        $this->validator->make(['args' => $args], $rules)->validate();
    }
}
