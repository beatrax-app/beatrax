<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\PairingFlowModal;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingAnswerability;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Enums\PairingWizardStep;

uses(RefreshDatabase::class);

// Showing a code is half a ceremony: the device that scans it has to send the
// answer back. Only one side of a pairing listens and no phone does, so a
// phone with no relay could be scanned and would never hear anything. Both
// screens looked mid-ceremony while one of them had never been spoken to.

function answerableUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Stated rather than read off this machine: RelayConfig reads a file that a
// developer's own install may well have, and the answer is the whole subject.
function answerabilityIs(bool $answerable): void
{
    app()->instance(PairingAnswerability::class, new class(app(RelayConfig::class), $answerable) extends PairingAnswerability
    {
        public function __construct(RelayConfig $relay, private readonly bool $answerable)
        {
            parent::__construct($relay);
        }

        public function canBeAnswered(): bool
        {
            return $this->answerable;
        }
    });
}

function answerableShowMyCode(User $user): object
{
    /** @var Session $session */
    $session = app(Session::class);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    return Livewire::test(PairingFlowModal::class)->call('showMyCode');
}

function answerableTokenCount(User $user): int
{
    return (int) app(DatabaseManager::class)->connection()
        ->table('pairing_tokens')->where('user_id', $user->id)->count();
}

it('refuses to draw a code nothing could answer', function (): void {
    $user = answerableUser('answer-none');
    $this->actingAs($user);
    answerabilityIs(false);

    answerableShowMyCode($user)
        ->assertSet('flashMessage', Lang::get('sync::pairing.cannot_be_answered'))
        ->assertNotSet('step', PairingWizardStep::ShowCode->value);
})->group('CodeNobodyCouldAnswer');

it('mints no token for the ceremony it refuses', function (): void {
    // A refusal that still issued a token would leave a row to expire, and the
    // sweeper would report a pairing the reader never began.
    $user = answerableUser('answer-none-token');
    $this->actingAs($user);
    answerabilityIs(false);

    answerableShowMyCode($user);

    expect(answerableTokenCount($user))->toBe(0);
})->group('CodeNobodyCouldAnswer');

it('draws the code where the answer has a road home', function (): void {
    // The control: nothing here may take the code away from a device that can
    // actually be answered, which is every desktop and a phone with a relay.
    $user = answerableUser('answer-yes');
    $this->actingAs($user);
    answerabilityIs(true);

    answerableShowMyCode($user)->assertSet('step', PairingWizardStep::ShowCode->value);

    expect(answerableTokenCount($user))->toBe(1);
})->group('CodeNobodyCouldAnswer');

it('answers for a desktop and refuses for a phone with no relay', function (): void {
    // The real rule, over the real collaborator: a desktop listens, so it is
    // answerable with no relay at all; a phone is not.
    app(RelayConfig::class)->setEndpointUrl(null);
    expect(app(PairingAnswerability::class)->canBeAnswered())->toBeTrue();

    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    try {
        expect(app(PairingAnswerability::class)->canBeAnswered())->toBeFalse();

        app(RelayConfig::class)->setEndpointUrl('https://relay.example.test:51338');
        expect(app(PairingAnswerability::class)->canBeAnswered())->toBeTrue();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
        app(RelayConfig::class)->setEndpointUrl(null);
    }
})->group('CodeNobodyCouldAnswer');
