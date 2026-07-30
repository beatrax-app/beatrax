<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;

/*
 * What `open-banking:serve-tls` decides before it binds anything.
 *
 * The command's body is a blocking socket proxy: it accepts on a TLS listener,
 * opens an upstream connection per client and pumps between them until a
 * signal arrives. That loop is exercised by manual UAT and is deliberately not
 * driven here — a test that bound real ports and raced a proxy against itself
 * would fail for reasons unrelated to the code under test.
 *
 * Everything the command decides BEFORE that loop is testable, and it is the
 * part that goes wrong: a misresolved port silently listens somewhere Enable
 * Banking has not registered, and the consent redirect never arrives. Each
 * case below returns before a socket is opened.
 */

beforeEach(function (): void {
    $this->tlsDir = sys_get_temp_dir().'/ob-serve-tls-'.bin2hex(random_bytes(6));
    app()->instance(LoopbackTlsCertificate::class, new LoopbackTlsCertificate($this->tlsDir));

    // A port nothing is listening on, so the reachability probe resolves
    // without depending on what else the machine happens to be running.
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

// Both ends on one port would have the listener proxy to itself, which
// presents as a hang rather than an error — worth refusing by name.
it('refuses to run both ends on the same port', function (): void {
    $this->artisan('open-banking:serve-tls', ['--port' => '9443', '--backend-port' => '9443'])
        ->expectsOutputToContain('must differ')
        ->assertExitCode(1);
});

// The listener's port is not its own setting: it is read back from the
// redirect URI the connector registers with Enable Banking, so the two cannot
// drift. Proven by moving the configured loopback port and watching the
// same-port refusal follow it.
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

// --no-backend means "tunnel to something already running". If nothing is
// there, saying so beats binding a listener that proxies every request into a
// closed port and reports each one as a bad gateway.
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

    // The printed digest must be the certificate's own, uppercase and
    // colon-grouped the way a browser's certificate viewer shows it.
    $digest = openssl_x509_fingerprint((string) file_get_contents($certPath), 'sha256');
    expect($digest)->not->toBeFalse()
        ->and(strtoupper(implode(':', str_split((string) $digest, 2))))->toMatch('/^[0-9A-F]{2}(:[0-9A-F]{2}){31}$/');
});
