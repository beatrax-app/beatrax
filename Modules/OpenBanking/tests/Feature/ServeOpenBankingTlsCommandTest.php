<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Modules\EmailScan\Public\LoopbackRedirectUri;
use Modules\OpenBanking\Internal\Console\ServeOpenBankingTlsCommand;
use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// The blocking loop is left to manual UAT (real ports race the proxy against
// itself). These cover the port resolution the command does before binding.

beforeEach(function (): void {
    $this->tlsDir = sys_get_temp_dir().'/ob-serve-tls-'.bin2hex(random_bytes(6));
    app()->instance(LoopbackTlsCertificate::class, new LoopbackTlsCertificate($this->tlsDir));

    // A port nothing is listening on, so the reachability probe is decisive.
    $this->deadPort = random_int(45000, 60000);
});

afterEach(function (): void {
    if (is_dir($this->tlsDir)) {
        foreach (glob($this->tlsDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tlsDir);
    }
});

it('refuses a port that is not a positive integer', function (array $options): void {
    $this->artisan('open-banking:serve-tls', $options)
        ->expectsOutputToContain('must be positive integers')
        ->assertExitCode(1);
})->with([
    'https port zero' => [['--port' => '0']],
    'backend port zero' => [['--backend-port' => '0']],
    'backend port negative' => [['--backend-port' => '-1']],
]);

// stream_socket_server() takes the low sixteen bits of the port rather than
// refusing one that does not fit: --port=99999 bound 127.0.0.1:34463 while the
// command announced https://127.0.0.1:99999, so the browser was sent to a port
// nothing was listening on.
it('refuses a port that does not fit in sixteen bits', function (array $options): void {
    $this->artisan('open-banking:serve-tls', $options)
        ->expectsOutputToContain('Both ports must be at most 65535')
        ->assertExitCode(1);
})->with([
    'https port past the ceiling' => [['--port' => '99999']],
    'https port one past the ceiling' => [['--port' => '65536']],
    'backend port past the ceiling' => [['--backend-port' => '70000']],
]);

// Both ends on one port would have the listener proxy to itself, which
// presents as a hang rather than an error — worth refusing by name.
it('refuses to run both ends on the same port', function (): void {
    $this->artisan('open-banking:serve-tls', ['--port' => '9443', '--backend-port' => '9443'])
        ->expectsOutputToContain('must differ')
        ->assertExitCode(1);
});

// The listener's port is not its own setting: it is read back from the redirect
// URI registered with Enable Banking, so the two cannot drift.
it('takes the HTTPS port from the registered redirect URI when none is given', function (): void {
    config()->set('email-scan.oauth_loopback_port', 9876);

    $this->artisan('open-banking:serve-tls', ['--backend-port' => '9876'])
        ->expectsOutputToContain('The HTTPS port (9876) and the backend port (9876) must differ.')
        ->assertExitCode(1);
});

it('prefers an explicit --port over the redirect URI', function (): void {
    config()->set('email-scan.oauth_loopback_port', 9876);

    $this->artisan('open-banking:serve-tls', ['--port' => '9123', '--backend-port' => '9123'])
        ->expectsOutputToContain('The HTTPS port (9123) and the backend port (9123) must differ.')
        ->assertExitCode(1);
});

// --no-backend means "tunnel to something already running": saying so beats
// binding a listener that reports every request as a bad gateway.
it('refuses --no-backend when nothing is listening on the backend port', function (): void {
    $this->artisan('open-banking:serve-tls', [
        '--port' => (string) ($this->deadPort + 1),
        '--backend-port' => (string) $this->deadPort,
        '--no-backend' => true,
    ])
        ->expectsOutputToContain('No backend is reachable')
        ->assertExitCode(1);
});

// The fingerprint is what the user compares against the certificate their
// browser shows before accepting it, so it is printed before anything binds.
it('prints the certificate fingerprint before it reaches the backend check', function (): void {
    $this->artisan('open-banking:serve-tls', [
        '--port' => (string) ($this->deadPort + 1),
        '--backend-port' => (string) $this->deadPort,
        '--no-backend' => true,
    ])
        ->expectsOutputToContain('fingerprint (SHA-256):')
        ->assertExitCode(1);

    $certPath = $this->tlsDir.'/cert.pem';
    expect($certPath)->toBeFile();

    // Uppercase and colon-grouped, the way a browser's certificate viewer shows it.
    $digest = openssl_x509_fingerprint((string) file_get_contents($certPath), 'sha256');
    expect($digest)->not->toBeFalse()
        ->and(strtoupper(implode(':', str_split((string) $digest, 2))))->toMatch('/^[0-9A-F]{2}(:[0-9A-F]{2}){31}$/');
});

// The fingerprint is decoration on the announcement, not a gate: a certificate
// this cannot read has to degrade to a word rather than take the command down
// before it reports the port it is about to bind.
it('says the fingerprint is unavailable rather than throwing out of the announcement', function (): void {
    $command = new ServeOpenBankingTlsCommand(
        app(LoopbackRedirectUri::class),
        new LoopbackTlsCertificate($this->tlsDir),
    );
    $method = new ReflectionMethod(ServeOpenBankingTlsCommand::class, 'fingerprint');
    $method->setAccessible(true);

    $absent = $this->tlsDir.'/never-written.pem';
    $notACertificate = sys_get_temp_dir().'/ob-not-a-cert-'.bin2hex(random_bytes(6)).'.pem';
    file_put_contents($notACertificate, 'plainly not a certificate');

    expect($method->invoke($command, $absent))->toBe('unavailable')
        ->and($method->invoke($command, $notACertificate))->toBe('unavailable');

    @unlink($notACertificate);
});

// The listener's port and the URI Enable Banking has registered are two
// different settings, and --port moves only one of them. Announced without a
// word, the operator is handed a redirect URI on a port nothing is serving.
it('says so when the announced redirect URI names another port than the listener', function (): void {
    config()->set('email-scan.oauth_loopback_port', 8000);

    $command = new ServeOpenBankingTlsCommand(
        app(LoopbackRedirectUri::class),
        new LoopbackTlsCertificate($this->tlsDir),
    );
    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    $method = new ReflectionMethod(ServeOpenBankingTlsCommand::class, 'announceReady');
    $method->setAccessible(true);

    $method->invoke($command, 9443, 8001, false);

    expect($output->fetch())
        ->toContain('https://127.0.0.1:8000/oauth/callback/open-banking')
        ->toContain('That URI names port 8000 and this listener is on 9443');
});

it('announces the redirect URI without a caveat when it names the bound port', function (): void {
    config()->set('email-scan.oauth_loopback_port', 8000);

    $command = new ServeOpenBankingTlsCommand(
        app(LoopbackRedirectUri::class),
        new LoopbackTlsCertificate($this->tlsDir),
    );
    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    $method = new ReflectionMethod(ServeOpenBankingTlsCommand::class, 'announceReady');
    $method->setAccessible(true);

    $method->invoke($command, 8000, 8001, false);

    expect($output->fetch())
        ->toContain('https://127.0.0.1:8000/oauth/callback/open-banking')
        ->not->toContain('the consent redirect will not reach it');
});
