<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Modules\Desktop\Internal\NativeAppServiceProvider;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

// boot() calls `view:cache` through the injected console Kernel, so tests that
// exercise boot() bind a spy rather than compile every project view under Pest.
function bindConsoleKernelSpy(): MockInterface
{
    $spy = Mockery::spy(ConsoleKernel::class);
    app()->instance(ConsoleKernel::class, $spy);

    return $spy;
}

// The Menu facade has no v2 fake and hits the NativePHP HTTP client at boot, so
// `Http::fake()` swallows those calls and the Window-fake assertions can run.
it('configures the application window', function (): void {
    Http::fake();
    bindConsoleKernelSpy();

    $fake = Window::fake();
    $fake->alwaysReturnWindows([new NativeWindow('main')]);

    app(NativeAppServiceProvider::class)->boot();

    $fake->assertOpened('main');
});

it('does not call the NativePHP `menu-bar/create` endpoint — the persistent tray lives in the Electron main process', function (): void {
    // `MenuBar::create()` lands in the popover menubar paradigm, whose context-
    // menu link items early-return when no window is focused — so "Open Beatrax"
    // does nothing once the user closes the window. The tray moved to the
    // Electron main process; a reintroduced call would show up as this POST.
    Http::fake();
    bindConsoleKernelSpy();
    Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);

    app(NativeAppServiceProvider::class)->boot();

    Http::assertNotSent(function (Request $request): bool {
        return str_ends_with($request->url(), 'menu-bar/create');
    });
});

it('runs the first-launch DB bootstrap before opening the main window — see FirstLaunchBootstrapTest for the post-boot assertion (Unit suite has no DB)')->todo();

it('configures the app menu via Native\\Desktop\\Facades\\Menu — deferred to manual UAT (no v2 fake for Menu)')->todo();

it('publishes php.ini overrides that lift the upload ceiling above the wizard validator', function (): void {
    // The bundled runtime ships the stock `upload_max_filesize = 2M` /
    // `post_max_size = 8M`, below the wizard's own validator, so an upload fails
    // at the PHP layer as "The files.0 failed to upload." with no context.
    // NativePHP reads these from phpIni(), so a rename here re-strands uploads.
    $provider = app(NativeAppServiceProvider::class);
    $phpIni = $provider->phpIni();

    expect($phpIni)->toHaveKey('upload_max_filesize');
    expect($phpIni)->toHaveKey('post_max_size');

    // 20M matches UploadWizard's largest single-file upload; post_max_size is
    // identical so the multipart envelope has headroom.
    expect($phpIni['upload_max_filesize'])->toBe('20M');
    expect($phpIni['post_max_size'])->toBe('20M');
});

it('publishes a max_execution_time override that absorbs the auto-updater Guzzle call', function (): void {
    // The bundled `php.ini` ships `max_execution_time = 30`, shorter than the
    // auto-updater's GitHub-feed Guzzle request on a slow network — the observed
    // production fatal in CurlFactory. Locked here so a rename cannot undo it.
    $provider = app(NativeAppServiceProvider::class);
    $phpIni = $provider->phpIni();

    expect($phpIni)->toHaveKey('max_execution_time');
    expect($phpIni['max_execution_time'])->toBe('120');
});

it('implements the NativePHP ProvidesPhpIni contract so the LoadPHPConfigurationCommand picks up the overrides', function (): void {
    $provider = app(NativeAppServiceProvider::class);

    expect($provider)->toBeInstanceOf(ProvidesPhpIni::class);
});

it('pre-warms the Blade view cache during boot to side-step the Windows rename race', function (): void {
    // On Windows two Livewire requests racing to compile the same uncached view
    // both go through Filesystem::replace(), and the loser's rename fails with
    // WinError 5. Pre-compiling at boot puts every view on disk first, so the
    // per-request compiler short-circuits and the rename path never runs.
    Http::fake();
    Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);
    $spy = bindConsoleKernelSpy();

    app(NativeAppServiceProvider::class)->boot();

    $spy->shouldHaveReceived('call')->with('view:cache')->once();
});

it('logs and continues when view:cache throws so a single broken view does not strand NativePHP at boot', function (): void {
    // `view:cache` aborts the whole compile loop on the first Blade syntax error,
    // and aborting boot here would leave the user with no app at all.
    Http::fake();
    Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);

    $kernel = Mockery::mock(ConsoleKernel::class);
    // boot() runs EnsureAppKey first, which mints a key unless the first-launch
    // sentinel is on disk. Each test gets a private storage root, so the call
    // always happens and the mock has to allow it explicitly.
    $kernel->shouldReceive('call')->with('key:generate', ['--force' => true])->andReturn(0);
    $kernel->shouldReceive('call')->with('view:cache')->andThrow(new RuntimeException('boom'));
    app()->instance(ConsoleKernel::class, $kernel);

    app(NativeAppServiceProvider::class)->boot();

    // Reaching this line without an exception is the assertion: boot() carried on
    // past the failed view:cache.
    expect(true)->toBeTrue();
});
