<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Build;

final readonly class StaleBundledRates
{
    private function __construct(
        private string $path,
        private string $found,
        private string $problem,
    ) {}

    public static function unreadable(string $path, string $reason): self
    {
        return new self($path, 'nothing a rate can be read out of', $reason);
    }

    public static function undated(string $path, string $raw): self
    {
        return new self($path, sprintf('"date": %s', json_encode($raw)), 'that is not a Y-m-d day');
    }

    public static function tooOld(string $path, string $date, int $ageDays, int $boundDays): self
    {
        return new self(
            $path,
            sprintf('"date": "%s"', $date),
            sprintf('%d days old, against a bound of %d', $ageDays, $boundDays),
        );
    }

    public function sentence(string $command): string
    {
        return sprintf(
            "The bundled exchange-rate snapshot cannot be shipped as it stands, so\n"
            ."  %s would package it.\n\n"
            ."    snapshot  %s\n"
            ."    found     %s\n"
            ."    problem   %s\n\n"
            ."  fx_online_enabled is the consent gate for this app's only outbound traffic and it\n"
            ."  is off by default, so on a stock install this file is not a fallback — it is the\n"
            ."  only thing pricing every cross-currency roll-up. The reader is told the figures are\n"
            ."  old; they are not told by how much, and nothing else in the build asks.\n\n"
            .'  Run `php scripts/refresh_bundled_rates.php`, then %s again.',
            $command,
            $this->path,
            $this->found,
            $this->problem,
            $command,
        );
    }
}
