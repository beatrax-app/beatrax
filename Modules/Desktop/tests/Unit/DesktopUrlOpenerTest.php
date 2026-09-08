<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\DesktopUrlOpener;
use Native\Desktop\Contracts\Shell;
use Native\Desktop\Fakes\ShellFake;

// This is the one file in the tree allowed to name the desktop Shell for a URL.
// The three that used to were in Modules/Community, which the phone also runs
// and cannot load a desktop-only type in.
it('hands the URL to the shell and reports that nothing said otherwise', function (): void {
    $shell = new ShellFake;

    expect((new DesktopUrlOpener($shell))->open('https://github.com/beatrax-app/beatrax'))->toBeTrue()
        ->and($shell->openExternalCalls)->toBe(['https://github.com/beatrax-app/beatrax']);
});

// openExternal() is typed void, so a throw is the only refusal it can express —
// outside the bundle it POSTs to a localhost port nothing is listening on.
it('reports a shell that threw as a URL that was not taken', function (): void {
    $shell = new class implements Shell
    {
        public function showInFolder(string $path): void {}

        public function openFile(string $path): string
        {
            return '';
        }

        public function trashFile(string $path): void {}

        public function openExternal(string $url): void
        {
            throw new RuntimeException('cURL error 7: Failed to connect to 127.0.0.1 port 4000');
        }
    };

    expect((new DesktopUrlOpener($shell))->open('https://github.com/beatrax-app/beatrax'))->toBeFalse();
});
