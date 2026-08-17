<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Mobile\Internal\Http\Middleware\EncodedUploadTransport;

/*
 * A file has to reach the pipeline on every platform, and on the iOS shell it
 * cannot cross as multipart at all: WebKit hands a custom scheme handler only
 * string bodies, so a FormData upload arrived as a multipart Content-Type over
 * a zero-byte php://input. It crosses base64-encoded instead.
 *
 * What these assert is that it is a *transport* and not a second import path:
 * the same temporary file lands on the same disk with the same bytes, whether
 * it arrived as multipart or encoded, and whether it is text or binary.
 */

/** The bytes as they lie on Livewire's temporary disk after an upload. */
function storedUpload(): string
{
    $disk = Storage::disk('livewire-tmp');
    $files = $disk->allFiles();

    expect($files)->toHaveCount(1);

    return (string) $disk->get($files[0]);
}

/** @param  array<int, array<string, mixed>>  $files */
function postEncoded(array $files): \Illuminate\Testing\TestResponse
{
    return test()->postJson(
        URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5)),
        [EncodedUploadTransport::FIELD => EncodedUploadTransport::MARKER, 'files' => $files],
    );
}

/** @return array<string, mixed> */
function encodedFile(string $contents, string $name): array
{
    return [
        'key' => 'files[]',
        'name' => $name,
        'type' => 'application/octet-stream',
        'size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
        'content' => base64_encode($contents),
    ];
}

beforeEach(function (): void {
    Storage::fake('livewire-tmp');
});

it('lands the same bytes a multipart upload would', function (): void {
    $contents = (string) file_get_contents(base_path('tests/fixtures/asn-sample-1.csv'));

    postEncoded([encodedFile($contents, 'asn-sample-1.csv')])->assertOk();

    expect(storedUpload())->toBe($contents);
});

it('carries binary as safely as text', function (): void {
    // Deliberately not valid UTF-8: the bytes a PDF is made of are exactly what
    // a string-typed bridge would have mangled, and base64 is what makes the
    // format restriction go away rather than merely move.
    $pdf = "%PDF-1.4\n".implode('', array_map(chr(...), range(128, 255)))."\n%%EOF";

    postEncoded([encodedFile($pdf, 'statement.pdf')])->assertOk();

    expect(storedUpload())->toBe($pdf)
        ->and(mb_check_encoding($pdf, 'UTF-8'))->toBeFalse();
});

it('refuses a file that did not arrive whole', function (): void {
    $entry = encodedFile('the whole statement', 'asn.csv');
    $entry['content'] = base64_encode('the whole st');

    postEncoded([$entry])->assertStatus(422);

    expect(Storage::disk('livewire-tmp')->allFiles())->toBeEmpty();
});

it('refuses a file whose bytes changed on the way', function (): void {
    $entry = encodedFile('the whole statement', 'asn.csv');
    $entry['sha256'] = hash('sha256', 'something else entirely');

    postEncoded([$entry])->assertStatus(422);

    expect(Storage::disk('livewire-tmp')->allFiles())->toBeEmpty();
});

it('leaves an ordinary multipart upload alone', function (): void {
    $response = test()->post(
        URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5)),
        ['files' => [UploadedFile::fake()->createWithContent('asn.csv', 'date,amount')]],
    );

    $response->assertOk();

    expect(storedUpload())->toBe('date,amount');
});
