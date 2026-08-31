<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Mechanisms\HandleComponents\ComponentContext;
use Modules\Core\Internal\Http\Livewire\SafeEnumSynth;
use Modules\Core\Public\Enums\JobRunStatus;

function enumSynthComponent(): Component
{
    return new class extends Component
    {
        public JobRunStatus $status = JobRunStatus::Running;

        public ?JobRunStatus $optional = JobRunStatus::Running;
    };
}

function enumSynthFor(Component $component, string $path): SafeEnumSynth
{
    return new SafeEnumSynth(new ComponentContext($component), $path);
}

it('hydrates a value the enum has a case for', function (): void {
    $synth = enumSynthFor(enumSynthComponent(), 'status');

    expect($synth->hydrate(JobRunStatus::Complete->value, ['class' => JobRunStatus::class]))
        ->toBe(JobRunStatus::Complete);
});

it('keeps the value the component already holds when the wire names no case', function (): void {
    $synth = enumSynthFor(enumSynthComponent(), 'status');

    expect($synth->hydrate('-1', ['class' => JobRunStatus::class]))->toBe(JobRunStatus::Running)
        ->and($synth->hydrate(['a' => 'b'], ['class' => JobRunStatus::class]))->toBe(JobRunStatus::Running)
        ->and($synth->hydrate(0, ['class' => JobRunStatus::class]))->toBe(JobRunStatus::Running);
});

it('leaves a property that cannot hold null holding its case on an empty value', function (): void {
    $synth = enumSynthFor(enumSynthComponent(), 'status');

    expect($synth->hydrate('', ['class' => JobRunStatus::class]))->toBe(JobRunStatus::Running)
        ->and($synth->hydrate(null, ['class' => JobRunStatus::class]))->toBe(JobRunStatus::Running);
});

it('still clears a nullable property on an empty value', function (): void {
    $synth = enumSynthFor(enumSynthComponent(), 'optional');

    expect($synth->hydrate('', ['class' => JobRunStatus::class]))->toBeNull()
        ->and($synth->hydrate(null, ['class' => JobRunStatus::class]))->toBeNull();
});

it('returns null rather than throwing when the property has no meta to go on', function (): void {
    expect(SafeEnumSynth::hydrateFromType(JobRunStatus::class, 'not-a-status'))->toBeNull()
        ->and(SafeEnumSynth::hydrateFromType(JobRunStatus::class, ['a' => 'b']))->toBeNull()
        ->and(SafeEnumSynth::hydrateFromType(JobRunStatus::class, JobRunStatus::Failed->value))
        ->toBe(JobRunStatus::Failed);
});

it('returns null when the meta names something that is not a backed enum', function (): void {
    $synth = enumSynthFor(enumSynthComponent(), 'status');

    expect($synth->hydrate('pending', ['class' => 'Not\\A\\Class']))->toBeNull()
        ->and($synth->hydrate('pending', []))->toBeNull();
});
