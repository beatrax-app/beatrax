<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\CloseActionController;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Psr\Log\LoggerInterface;

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

// The endpoint re-validates the choice rather than trusting the JS hook, and
// the rejection has to log: the hook ignores the response, so a silent
// rejection would leave no operator-side signal that garbage was posted.
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
    // A Livewire-event-name typo or browser autofill can surface as a
    // missing `choice` field, not just an off-allow-list string.
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
