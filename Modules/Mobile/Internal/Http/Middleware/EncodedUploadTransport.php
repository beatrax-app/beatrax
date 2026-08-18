<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

// WebKit hands a custom-scheme handler only string request bodies, so a
// FormData upload reached PHP as a multipart Content-Type over a zero-byte
// php://input. The client encodes the file into JSON instead and this puts a
// real UploadedFile back, so nothing behind it can tell the two apart.
/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class EncodedUploadTransport
{
    public const FIELD = '_beatrax_transport';

    public const MARKER = 'base64';

    // Refuses rather than repairs. A statement that arrives short would import
    // as a truncated one, and a wrong number silently in the ledger is worse
    // than an upload the user can see failed and retry.
    private const REJECTION = 422;

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
        $decoded = is_string($content) ? base64_decode($content, true) : false;

        if ($decoded === false) {
            throw new HttpException(self::REJECTION, 'The upload was not valid base64.');
        }

        $declaredSize = $entry['size'] ?? null;

        if (! is_int($declaredSize) || strlen($decoded) !== $declaredSize) {
            throw new HttpException(self::REJECTION, 'The upload did not arrive whole.');
        }

        $declaredDigest = $entry['sha256'] ?? null;

        if (! is_string($declaredDigest) || ! hash_equals(hash('sha256', $decoded), $declaredDigest)) {
            throw new HttpException(self::REJECTION, 'The upload did not survive the crossing intact.');
        }

        $path = tempnam(sys_get_temp_dir(), 'beatrax-upload-');

        if ($path === false || file_put_contents($path, $decoded) === false) {
            throw new HttpException(500, 'The upload could not be staged for parsing.');
        }

        return $path;
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
