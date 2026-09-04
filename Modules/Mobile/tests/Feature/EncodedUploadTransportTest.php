<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Mobile\Internal\Http\Middleware\EncodedUploadTransport;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// Livewire writes a .json metadata sidecar beside each upload, so the upload
// itself is what is left once those are set aside.
function storedUpload(): string
{
    $disk = Storage::disk(FileUploadConfiguration::disk());
    $files = array_values(array_filter(
        $disk->allFiles(),
        static fn (string $path): bool => ! str_ends_with($path, '.json'),
    ));

    expect($files)->toHaveCount(1);

    return (string) $disk->get($files[0]);
}

/** @param  array<int, array<string, mixed>>  $files */
function postEncoded(array $files): TestResponse
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
    Storage::fake(FileUploadConfiguration::disk());

    // The upload endpoint sits behind the web guard, and UserScope is
    // fail-closed for web requests — an unauthenticated post does not merely
    // redirect, the first scoped query throws.
    test()->actingAs(User::query()->create([
        'username' => 'encoded-upload-user',
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]));
});

// WebKit hands a custom scheme handler string bodies only, so on the iOS shell a
// FormData upload arrived as a multipart Content-Type over a zero-byte
// php://input. It crosses base64-encoded instead, and what these assert is that
// the encoding is a transport rather than a second import path.

it('lands the same bytes a multipart upload would', function (): void {
    $contents = (string) file_get_contents(base_path('tests/fixtures/asn-sample-1.csv'));

    postEncoded([encodedFile($contents, 'asn-sample-1.csv')])->assertOk();

    expect(storedUpload())->toBe($contents);
});

it('carries binary as safely as text', function (): void {
    // Deliberately not valid UTF-8: the bytes a PDF is made of are exactly what
    // a string-typed bridge would have mangled.
    $pdf = "%PDF-1.4\n".implode('', array_map(chr(...), range(128, 255)))."\n%%EOF";

    postEncoded([encodedFile($pdf, 'statement.pdf')])->assertOk();

    expect(storedUpload())->toBe($pdf)
        ->and(mb_check_encoding($pdf, 'UTF-8'))->toBeFalse();
});

it('refuses a file that did not arrive whole', function (): void {
    $entry = encodedFile('the whole statement', 'asn.csv');
    $entry['content'] = base64_encode('the whole st');

    postEncoded([$entry])->assertStatus(422);

    expect(Storage::disk(FileUploadConfiguration::disk())->allFiles())->toBeEmpty();
});

it('refuses a file whose bytes changed on the way', function (): void {
    $entry = encodedFile('the whole statement', 'asn.csv');
    $entry['sha256'] = hash('sha256', 'something else entirely');

    postEncoded([$entry])->assertStatus(422);

    expect(Storage::disk(FileUploadConfiguration::disk())->allFiles())->toBeEmpty();
});

// Every test above fixes the payload at a string literal, and size is the only
// axis this transport ever failed on: the decode used to materialise the whole
// file beside the raw body and the parsed base64 copy — about four times the
// file live at once — and a phone's 128 MB ceiling gave out at the size three
// other places in the product advertise as the supported maximum.

it('refuses a file past the advertised maximum instead of decoding it into a fatal', function (): void {
    // Declared, not decoded: a ceiling exhausted mid-decode is E_ERROR, which
    // is not catchable, answers nothing and logs nothing.
    $entry = encodedFile('a real but small body', 'huge.csv');
    $entry['size'] = EncodedUploadTransport::MAX_BYTES + 1;

    postEncoded([$entry])->assertStatus(422);

    expect(Storage::disk(FileUploadConfiguration::disk())->allFiles())->toBeEmpty();
});

it('stages a file without ever holding a decoded copy of it', function (): void {
    // Driven at the middleware rather than through the route: the test client
    // and Livewire's own staging allocate several times the payload between
    // them, and that noise is exactly what hid the growth being measured here.
    $contents = random_bytes(8 * 1024 * 1024);

    $request = Request::create(
        '/livewire/upload-file',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode([
            EncodedUploadTransport::FIELD => EncodedUploadTransport::MARKER,
            'files' => [encodedFile($contents, 'big.csv')],
        ], JSON_THROW_ON_ERROR),
    );

    // Warms Laravel's JSON parse, so the raw body and the parsed base64 copy
    // are already standing when the measurement starts. Those two are what the
    // wire costs; the third copy is what the decode used to add.
    $request->input(EncodedUploadTransport::FIELD);

    $staged = null;

    memory_reset_peak_usage();
    // Byte-accurate rather than the allocator's 2 MB chunk granularity: the
    // growth being measured is one copy of the payload, not chunk churn.
    $before = memory_get_usage();

    // Digested rather than read back: a file_get_contents() here would be a
    // whole further copy of its own, measured as if the transport had made it.
    app(EncodedUploadTransport::class)->handle($request, function (Request $handled) use (&$staged): SymfonyResponse {
        $files = $handled->files->get('files');
        $staged = is_array($files) && $files[0] instanceof UploadedFile
            ? hash_file('sha256', $files[0]->getPathname())
            : null;

        return new SymfonyResponse('', 204);
    });

    $peakDelta = memory_get_peak_usage() - $before;

    expect($staged)->toBe(hash('sha256', $contents));
    expect($peakDelta)->toBeLessThan(
        intdiv(strlen($contents), 2),
        'the decode must not add a whole further copy of the file to the peak',
    );
});

it('refuses a body naming more files than one pick can produce', function (): void {
    // post_max_size bounds the bytes and nothing bounded the count, so a body
    // of ten thousand one-byte entries was ten thousand temp files.
    $entries = array_fill(0, EncodedUploadTransport::MAX_FILES + 1, encodedFile('x', 'a.csv'));

    postEncoded($entries)->assertStatus(422);

    expect(Storage::disk(FileUploadConfiguration::disk())->allFiles())->toBeEmpty();
});

it('still refuses a payload that is not base64 at all', function (): void {
    $entry = encodedFile('the whole statement', 'asn.csv');
    $entry['content'] = 'not base64 at all!!';

    postEncoded([$entry])->assertStatus(422);

    expect(Storage::disk(FileUploadConfiguration::disk())->allFiles())->toBeEmpty();
});

// The maximum is stated in four places that cannot see each other: this
// transport, the client that refuses the pick, and the two shells' php.ini
// patches. A body the client sends and the transport refuses is a wasted
// upload; one the shell drops is a request that never arrives at all.
it('states the same upload maximum as the client and both native shells', function (): void {
    // Read as source, not required: both shell scripts run their patch on
    // include and exit, which would end the test run rather than the assertion.
    $advertised = intdiv(EncodedUploadTransport::MAX_BYTES, 1024 * 1024);

    $declared = [
        'scripts/nativephp_ios_upload_limits.php' => "/BEATRAX_IOS_UPLOAD_MAX_FILESIZE\s*=\s*'(\d+)M'/",
        'scripts/nativephp_android_upload_limits.php' => "/BEATRAX_ANDROID_UPLOAD_MAX_FILESIZE\s*=\s*'(\d+)M'/",
        'resources/js/mobile-upload.js' => '/MAX_UPLOAD_BYTES\s*=\s*(\d+)\s*\*\s*1024\s*\*\s*1024/',
    ];

    // base_path() is mobile-app/ in the second Composer root, and scripts/ is a
    // real directory there rather than a symlink to this one, so a repo-root
    // script is a level up. Read blind, this passes from one root and dies on
    // file_get_contents from the other.
    $repoRoot = static function (string $file): string {
        $fromHere = base_path($file);

        return is_file($fromHere) ? $fromHere : base_path('../'.$file);
    };

    foreach ($declared as $file => $pattern) {
        $found = PatternScan::first($pattern, (string) file_get_contents($repoRoot($file)));

        expect($found)->not->toBe([], "{$file} must still declare the upload maximum in whole megabytes");
        expect((int) $found[1])->toBe($advertised, "{$file} states a different maximum than the transport enforces");
    }
});

it('leaves an ordinary multipart upload alone', function (): void {
    $response = test()->post(
        URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5)),
        ['files' => [UploadedFile::fake()->createWithContent('asn.csv', 'date,amount')]],
    );

    $response->assertOk();

    expect(storedUpload())->toBe('date,amount');
});
