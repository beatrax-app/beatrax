<?php

declare(strict_types=1);

// A component that raises the operating system's notification prompt is worth
// nothing where nobody mounts it. The prompt used to be raised from the
// notification settings form alone, while the shipped defaults post reminders
// and budget nudges, so a reader who never opened that form was never asked
// and the OS dropped everything for the life of the install.

function osPromptLayout(): string
{
    foreach (['resources/views/layouts/app.blade.php', '../resources/views/layouts/app.blade.php'] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

it('finds the application layout it is written against', function (): void {
    expect(osPromptLayout())->not->toBe('');
});

it('mounts the prompt in the layout every application page renders through', function (): void {
    expect(osPromptLayout())->toContain("@livewire('mobile.notification-permission')");
});

it('mounts it on the phone only, where an OS grant is what gates delivery', function (): void {
    $layout = osPromptLayout();
    $mount = mb_strpos($layout, "@livewire('mobile.notification-permission')");

    expect($mount)->not->toBeFalse();

    // The guard opens above the mount and closes below it. Read as the text
    // between the nearest preceding runtime check and the mount, which is
    // empty of any other @endif when the mount is genuinely inside it.
    $guard = mb_strrpos(mb_substr($layout, 0, (int) $mount), 'UserDataPathService::isMobileRuntime()');

    expect($guard)->not->toBeFalse();

    $between = mb_substr($layout, (int) $guard, (int) $mount - (int) $guard);

    expect($between)->not->toContain('@endif');
});
