<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Tax\Internal\Services\TaxPdfRenderer;
use Modules\Tax\Internal\Services\TaxYearQuery;

function tpdfUser(DatabaseManager $db, string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tpdfTransaction(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TPDF ASN '.$suffix,
        'slug' => 'tpdf-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tpdf-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tpdf-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tpdf-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2025-06-01',
        'booked_at' => '2025-06-01 00:00:00',
        'value_date' => '2025-06-01',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'tpdf-vendor',
        'counterparty_name' => 'TPDF Vendor BV',
        'normalization_version' => 1,
        'description' => 'TPDF test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(
        array_merge($defaults, $overrides),
    );
}

function tpdfCategory(DatabaseManager $db, int $userId, string $name = 'Zorgkosten'): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tpdfTag(DatabaseManager $db, int $userId, int $txId, ?int $catId = null, array $overrides = []): void
{
    $db->connection()->table('tax_transaction_tags')->insert(array_merge([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => $catId,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('render() returns a non-empty string starting with the PDF magic header', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tpdfUser($db, 'tpdf-magic-user');

    /** @var TaxPdfRenderer $renderer */
    $renderer = app(TaxPdfRenderer::class);
    $pdf = $renderer->render($user, 2025);

    expect($pdf)->not->toBeEmpty()
        ->and(substr($pdf, 0, 5))->toBe('%PDF-');
});

it('a year with tagged transactions produces a larger output than an empty year', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tpdfUser($db, 'tpdf-size-user');

    $catId = tpdfCategory($db, $user->id, 'Zorgkosten');
    $txId = tpdfTransaction($db, $user->id, ['booked_at' => '2025-03-15 00:00:00']);
    tpdfTag($db, $user->id, $txId, $catId, ['note' => 'doctor visit']);

    /** @var TaxPdfRenderer $renderer */
    $renderer = app(TaxPdfRenderer::class);

    $emptyPdf = $renderer->render($user, 2024); // no tags in 2024
    $filledPdf = $renderer->render($user, 2025); // has tags

    expect(strlen($filledPdf))->toBeGreaterThan(strlen($emptyPdf));
});

it('a free-text note with <script> is HTML-escaped in the rendered view HTML', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tpdfUser($db, 'tpdf-xss-user');

    $catId = tpdfCategory($db, $user->id, 'Overig');
    $txId = tpdfTransaction($db, $user->id, ['booked_at' => '2025-07-01 00:00:00']);
    tpdfTag($db, $user->id, $txId, $catId, ['note' => '<script>alert(1)</script>']);

    // Assert against the view HTML, not the PDF bytes: dompdf font embedding
    // makes binary assertions fragile.
    $data = app(TaxYearQuery::class)->forUser($user->id, 2025);
    $html = view('tax::pdf.export', ['year' => 2025, 'data' => $data])->render();

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
});

it('dompdf Options has isRemoteEnabled set to false in the renderer source', function (): void {
    $rendererPath = __DIR__.'/../../Internal/Services/TaxPdfRenderer.php';
    $source = file_get_contents($rendererPath);

    expect($source)->not->toBeFalse()
        ->and((string) $source)->toContain('isRemoteEnabled');

    expect((string) $source)->toContain("'isRemoteEnabled', false");
});

// Helvetica is a PDF core font: nothing is embedded, and the reader supplies
// the glyphs. Where its Helvetica has no euro sign — macOS Preview is one —
// the substitute is drawn at the width the core metrics declare, and every
// euro amount in the export overlaps its first digit. An embedded subset
// carries the glyph and its own advance, so the figure reads the same
// everywhere.
it('embeds the font the money is drawn with rather than naming a core font', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tpdfUser($db, 'tpdf-euro-user');

    $catId = tpdfCategory($db, $user->id, 'Zorgkosten');
    $txId = tpdfTransaction($db, $user->id, ['booked_at' => '2025-06-28 00:00:00', 'settled_amount_minor' => -14250]);
    tpdfTag($db, $user->id, $txId, $catId);

    $pdf = app(TaxPdfRenderer::class)->render($user, 2025);

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toContain('/FontFile2')
        ->and($pdf)->not->toContain('/BaseFont /Helvetica');
});

// The Subtotal row under each category table sits below a summary block headed
// "Total deductions", and it folded a tagged income row into that figure.
it('subtotals the category tables to the deductions figure the summary block states', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = tpdfUser($db, 'tpdf-mixed-subtotal');

    $catId = tpdfCategory($db, $user->id, 'Zorgkosten');
    tpdfTag($db, $user->id, tpdfTransaction($db, $user->id, [
        'booked_at' => '2025-03-15 00:00:00',
        'amount_minor' => -135_544,
        'settled_amount_minor' => -135_544,
    ]), $catId);
    tpdfTag($db, $user->id, tpdfTransaction($db, $user->id, [
        'booked_at' => '2025-04-15 00:00:00',
        'amount_minor' => 20_000,
        'settled_amount_minor' => 20_000,
        'type' => 'income',
    ]), $catId);

    $data = app(TaxYearQuery::class)->forUser($user->id, 2025);
    $html = view('tax::pdf.export', ['year' => 2025, 'data' => $data])->render();

    preg_match_all('#<tr class="subtotal-row">.*?</tr>#s', $html, $rows);

    expect($rows[0][0] ?? '')->toContain(e(Money::ofMinor(135_544, 'EUR')->format()))
        ->not->toContain(e(Money::ofMinor(155_544, 'EUR')->format()));

    expect($rows[0])->toHaveCount(2)
        ->and($rows[0][1])->toContain(e(Money::ofMinor(20_000, 'EUR')->format()));
});
