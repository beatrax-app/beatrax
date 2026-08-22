<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Mobile\Internal\Boot\IosSigningPreflight;
use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('says nothing when the team id is configured', function (): void {
    expect(IosSigningPreflight::teamIdWarning('NV5645J73B'))->toBeNull();
});

it('warns for an unset or blank team id and names the variable to set', function (?string $configured): void {
    $warning = IosSigningPreflight::teamIdWarning($configured);

    expect($warning)->toBeString();
    expect($warning)->toContain('IOS_TEAM_ID');
    expect($warning)->toContain('security find-identity');
})->with([null, '', '   ']);

// The same listener re-applies the fifteen build patches. Rebinding the runner
// keeps a unit test from rewriting the developer's real generated project, and
// from spending eight seconds spawning PHP to decide not to.
function preflightOutputFor(string $command): string
{
    app()->make(ConfigRepository::class)->set('nativephp.development_team', null);
    app()->bind(NativeBuildPatches::class, fn () => new class
    {
        public function apply(string $scriptsDirectory): void
        {
            // Deliberately inert: the patches are proven elsewhere.
        }
    });

    $output = new BufferedOutput;

    event(new CommandStarting($command, new ArrayInput([]), $output));

    return $output->fetch();
}

it('puts the warning in front of a mobile build rather than in a log nobody opens', function (): void {
    expect(preflightOutputFor('native:build'))->toContain('IOS_TEAM_ID');
});

it('stays quiet for commands that do not build anything native', function (): void {
    expect(preflightOutputFor('migrate'))->toBe('');
});
