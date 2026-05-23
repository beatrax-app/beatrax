<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\CloseActionController;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Psr\Log\LoggerInterface;

/*
 * Feature tests for the D-08 close-action HTTP endpoint
 * (`desktop.close-action`). The endpoint receives the JS hook's POST,
 * re-validates the choice against `WindowCloseBehavior::CHOICE_*` as a
 * defence-in-depth check (T-15-22 in the plan 15-03 threat register),
 * and routes the validated choice through `ApplyCloseWindowChoice`.
 *
 * The "off-allow-list payload" branch must produce a log entry — the
 * in-layout JS hook ignores the response, so a silent rejection would
 * leave no operator-side signal when a regression sends garbage to the
 * endpoint. The happy-path applies the choice via the listener; the
 * listener itself calls the `App::quit()` / `Window::current()->hide()`
 * facades which have no v2 PHP fakes (NATIVEPHP-FAKES.md), so the
 * happy-path is left to manual UAT.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'close-action-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

/**
 * Builds an in-memory PSR-3 logger that appends every recorded entry
 * to the provided array reference. Returned as `LoggerInterface` so
 * the controller's constructor accepts it via container instance
 * binding.
 *
 * @param  array<int, array{level: string, message: string, context: array<string, mixed>}>  $sink
 */
function inMemoryLoggerSink(array &$sink): LoggerInterface
{
    return new class($sink) implements LoggerInterface
    {
        /** @param array<int, array{level: string, message: string, context: array<string, mixed>}> $log */
        public function __construct(public array &$log) {}

        public function emergency(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'emergency', 'message' => (string) $message, 'context' => $context];
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'alert', 'message' => (string) $message, 'context' => $context];
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'critical', 'message' => (string) $message, 'context' => $context];
        }

        public function error(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'error', 'message' => (string) $message, 'context' => $context];
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'warning', 'message' => (string) $message, 'context' => $context];
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'notice', 'message' => (string) $message, 'context' => $context];
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'info', 'message' => (string) $message, 'context' => $context];
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => 'debug', 'message' => (string) $message, 'context' => $context];
        }

        /** @param array<string, mixed> $context */
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->log[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

it('logs a warning and returns 422 when the POSTed choice is off the allow-list', function (): void {
    $messages = [];
    $logger = inMemoryLoggerSink($messages);

    $this->app->instance(CloseActionController::class, new CloseActionController(
        $this->app->make(ApplyCloseWindowChoice::class),
        $logger,
    ));

    $response = $this->postJson(route('desktop.close-action'), ['choice' => 'garbage']);
    $response->assertStatus(422);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['level'])->toBe('warning');
    expect($messages[0]['message'])->toContain('off-allow-list choice');
    expect($messages[0]['context']['choice'] ?? null)->toBe('garbage');
})->group('phase-15');

it('logs a warning when the POSTed choice is missing entirely', function (): void {
    // Browser autofill garbage or a Livewire-event-name typo could
    // surface as a missing `choice` field — the controller must reject
    // and log the same way it does for an off-allow-list string.
    $messages = [];
    $logger = inMemoryLoggerSink($messages);

    $this->app->instance(CloseActionController::class, new CloseActionController(
        $this->app->make(ApplyCloseWindowChoice::class),
        $logger,
    ));

    $response = $this->postJson(route('desktop.close-action'), []);
    $response->assertStatus(422);

    expect($messages)->toHaveCount(1);
    expect($messages[0]['level'])->toBe('warning');
})->group('phase-15');
