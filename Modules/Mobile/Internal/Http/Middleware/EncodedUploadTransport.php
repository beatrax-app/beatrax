<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Core\Public\Services\UserDataPathService;
use Psr\Log\LoggerInterface;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

// WebKit hands a custom-scheme handler only string request bodies, so a
// FormData upload reached PHP as a multipart Content-Type over a zero-byte
// php://input. The client encodes the file into JSON instead and this puts a
// real UploadedFile back, so nothing behind it can tell the two apart.
final class EncodedUploadTransport
{
    // Every way staging can fail reads the same to the caller: the file did
    // not reach the parser. What went wrong is for the log, not the response.
    private const string STAGING_FAILED_MESSAGE = 'The upload could not be staged for parsing.';

    public const string FIELD = '_beatrax_transport';

    public const string MARKER = 'base64';

    // The size the product advertises as its maximum, in three other places:
    // resources/js/mobile-upload.js and the two shells' php.ini patches. This
    // is where the promise is kept — a body past it is refused rather than
    // decoded into a fatal the reader is never told about.
    public const MAX_BYTES = 20 * 1024 * 1024;

    // One file per pick on the client, so a body naming more than a handful is
    // not a bigger upload but a different caller. post_max_size bounds the
    // bytes; nothing bounded the count.
    public const int MAX_FILES = 8;

    // Refuses rather than repairs. A statement that arrives short would import
    // as a truncated one, and a wrong number silently in the ledger is worse
    // than an upload the user can see failed and retry.
    private const int REJECTION = 422;

    // A multiple of 4, so every slice is a whole number of base64 quanta and
    // decodes standalone.
    private const DECODE_CHUNK = 1 << 19;

    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    public function __construct(
        private readonly OwnerOnlyPath $ownerOnly,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->input(self::FIELD) !== self::MARKER) {
            return $next($request);
        }

        $scratchPaths = [];

        try {
            $files = [];

            foreach ($this->entries($request) as $entry) {
                $path = $this->write($entry);
                $scratchPaths[] = $path;
                $files[] = $this->uploaded($entry, $path);
            }

            // The encoded copies would otherwise still be sitting in the input
            // bag under the same key, where `request('files')` merges both.
            $request->json()->remove('files');
            $request->json()->remove(self::FIELD);

            $request->files->set('files', $files);

            return $next($request);
        } finally {
            foreach ($scratchPaths as $scratchPath) {
                if (is_file($scratchPath)) {
                    unlink($scratchPath);
                }
            }
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function entries(Request $request): array
    {
        $files = $request->input('files');

        if (! is_array($files) || $files === []) {
            throw new HttpException(self::REJECTION, 'The upload carried no files.');
        }

        if (count($files) > self::MAX_FILES) {
            throw new HttpException(self::REJECTION, 'The upload carried more files than this transport accepts.');
        }

        $entries = [];

        foreach ($files as $entry) {
            if (! is_array($entry)) {
                throw new HttpException(self::REJECTION, 'The upload was not shaped as this transport sends it.');
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    // The decoded bytes on disk, proven identical to the ones the client read.
    // Refuses rather than repairs: a statement that arrives short would import
    // as a truncated one, and a wrong number in the ledger is worse than an
    // upload the user can see failed.
    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function write(array $entry): string
    {
        $content = $entry['content'] ?? null;

        if (! is_string($content) || ! self::isBase64($content)) {
            throw new HttpException(self::REJECTION, 'The upload was not valid base64.');
        }

        $declaredSize = $entry['size'] ?? null;

        if (! is_int($declaredSize) || $declaredSize < 0) {
            throw new HttpException(self::REJECTION, 'The upload did not arrive whole.');
        }

        // Checked from the DECLARED size, before a byte is decoded. The
        // decoded copy is what exhausts a phone's 128 MB ceiling, and an
        // exhausted ceiling is E_ERROR — no 422, no log, no retry.
        if ($declaredSize > self::MAX_BYTES) {
            throw new HttpException(self::REJECTION, 'The upload is larger than this device accepts.');
        }

        // A 0700 directory under app storage, never sys_get_temp_dir(): these
        // bytes are somebody's bank statement, and /tmp is world-traversable
        // at 1777, so the name and size are readable even at 0600.
        $dir = rtrim(UserDataPathService::appPath('tmp-uploads'), '/');

        $path = $this->stagedPath($dir);

        [$written, $digest] = $this->decodeInto($content, $path);

        if ($written !== $declaredSize) {
            throw new HttpException(self::REJECTION, 'The upload did not arrive whole.');
        }

        $declaredDigest = $entry['sha256'] ?? null;

        if (! is_string($declaredDigest) || ! hash_equals($digest, $declaredDigest)) {
            throw new HttpException(self::REJECTION, 'The upload did not survive the crossing intact.');
        }

        return $path;
    }

    // tempnam() does not fail on a directory it cannot write to: it creates
    // the file in sys_get_temp_dir() instead, returns THAT path, and says so
    // in a notice. Suppressed on purpose — a notice is not a guarantee, and
    // the path it hands back is. The stray file is removed, not left at 1777.
    private function stagedPath(string $dir): string
    {
        if (! $this->ownerOnly->directory($dir)) {
            $this->refuseStaging('the staging directory could not be narrowed to its owner');
        }

        $path = @tempnam($dir, 'beatrax-upload-');

        if ($path === false) {
            $this->refuseStaging('tempnam() could not name a file for the upload');
        }

        $staged = realpath($path);

        if ($staged === false || dirname($staged) !== realpath($dir)) {
            @unlink($path);
            $this->refuseStaging('the staging directory was not writable, and tempnam() fell back to the system temp directory');
        }

        return $staged;
    }

    // Every way staging can fail reads the same to the caller; the reason it
    // failed is for the log, which this class named and never wrote to.
    private function refuseStaging(string $why): never
    {
        $this->logger->warning('EncodedUploadTransport: upload staging refused — '.$why.'.');

        throw new HttpException(500, self::STAGING_FAILED_MESSAGE);
    }

    // Decoded a slice at a time straight onto disk. Holding the whole decoded
    // copy alongside the raw body and the parsed base64 string cost about four
    // times the file, and the supported 20 MB maximum fatalled on line one of
    // the decode — at the size three other places in the product advertise.
    /**
     * @return array{0: int, 1: string} [bytes written, sha256 of what was written]
     */
    private function decodeInto(string $content, string $path): array
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->refuseStaging('the staged file could not be opened for writing');
        }

        $hash = hash_init('sha256');
        $written = 0;
        $length = strlen($content);

        try {
            for ($offset = 0; $offset < $length; $offset += self::DECODE_CHUNK) {
                // isBase64() cleared the alphabet and the quantum alignment for
                // the whole body, so strict re-walks a slice that cannot fail.
                // The refusal is still written out: a decode that returns false
                // is not repaired into whatever bytes survived it.
                $bytes = base64_decode(substr($content, $offset, self::DECODE_CHUNK), true);

                if ($bytes === false) {
                    throw new HttpException(self::REJECTION, 'The upload did not survive the crossing intact.');
                }

                hash_update($hash, $bytes);
                $written += strlen($bytes);

                if (fwrite($handle, $bytes) === false) {
                    throw new HttpException(500, self::STAGING_FAILED_MESSAGE);
                }
            }
        } finally {
            fclose($handle);
        }

        return [$written, hash_final($hash)];
    }

    // Alphabet and quantum alignment in one linear pass and no allocation, so
    // the per-slice decodes below can be trusted without each re-validating.
    // Padding is only ever the last one or two characters.
    private static function isBase64(string $content): bool
    {
        $length = strlen($content);

        if ($length % 4 !== 0) {
            return false;
        }

        $padding = 0;

        while ($padding < 2 && $padding < $length && $content[$length - $padding - 1] === '=') {
            $padding++;
        }

        return strspn($content, self::ALPHABET) === $length - $padding;
    }

    // `test: true` because no SAPI moved this file into place — the bytes came
    // over the bridge — so the is_uploaded_file() check it skips would be asking
    // a question that cannot be true here.
    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function uploaded(array $entry, string $path): UploadedFile
    {
        $name = $entry['name'] ?? null;
        $type = $entry['type'] ?? null;

        return new UploadedFile(
            $path,
            is_string($name) && $name !== '' ? $name : 'upload',
            is_string($type) && $type !== '' ? $type : null,
            null,
            true,
        );
    }
}
