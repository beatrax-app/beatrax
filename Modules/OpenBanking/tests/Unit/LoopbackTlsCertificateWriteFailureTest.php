<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Tls\LoopbackTlsCertificate;

// Both halves are written before either is chmodded, so a silently failed write
// would leave the serve command handing out a stale key. The `=== false` guard
// only decides anything because the call is suppressed.
it('refuses to report a certificate it could not write', function (): void {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-tls-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    // A directory where the certificate file belongs: the write refuses it.
    mkdir($dir.DIRECTORY_SEPARATOR.'cert.pem');

    expect(fn () => (new LoopbackTlsCertificate($dir))->ensure())
        ->toThrow(RuntimeException::class, 'Unable to write the loopback TLS certificate');

    @rmdir($dir.DIRECTORY_SEPARATOR.'cert.pem');
    foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'*') as $f) {
        is_dir((string) $f) ? @rmdir((string) $f) : @unlink((string) $f);
    }
    @rmdir($dir);
});
