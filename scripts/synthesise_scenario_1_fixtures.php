#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Synthesises the cross-source matching fixture trio:
 *
 *   Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml
 *   Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf
 *   Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv
 *   Modules/Chains/tests/fixtures/scenario-1/scenario-1.md
 *   Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json
 *   Modules/Chains/tests/fixtures/scenario-1/scenario-1-underpaid.json
 *
 * The fixture demonstrates cross-source chain matching:
 *
 *   - ASN CAMT.053 statement carries ONE bulk-iDEAL debit of €847,32
 *     payable to the synthetic ICS-CARD counterparty.
 *   - ICS PDF statement covers the SAME period (15 April → 14 May 2026)
 *     with 23 transactions whose settled-EUR amounts sum to €847,32 —
 *     21 EUR-native rows plus 2 USD FX rows (each in the two-line
 *     "Wisselkoers" shape).
 *   - PayPal CSV carries 14 rows in the empirical NL profile, including
 *     one "Bankstorting" row with an inferable destination IBAN
 *     matching the ASN synthetic account, plus one deterministic
 *     Reference-Txn-ID chain and one four-row USD FX chain.
 *
 * The script is composer-dep-free: no FPDF / TCPDF, no XML library, no
 * league/csv. Hand-crafted PDF 1.4 byte stream (mirroring
 * `scripts/generate_tiny_ics_pdf.php`); CAMT.053 emitted as a literal
 * UTF-8 XML string; CSV emitted via fputcsv with `,` delimiter.
 *
 * The synthetic PayPal CSV uses the rollup-event log shape PayPal
 * actually emits: each logical payment produces a parent row + its
 * funding-sweep child, and the FX chain produces four sibling rows
 * with shared Reference Txn IDs (parent + currency-conversion legs).
 *
 * Idempotency invariant: re-running on a clean filesystem produces
 * byte-identical output. mt_srand(20260516) is seeded once at the top;
 * every other choice is deterministic from the hard-coded amount /
 * merchant tables in this file.
 *
 * Usage:
 *     php scripts/synthesise_scenario_1_fixtures.php
 */
mt_srand(20260516);

$repoRoot = dirname(__DIR__);
$fixtureDir = $repoRoot.'/Modules/Chains/tests/fixtures/scenario-1';
if (! is_dir($fixtureDir)) {
    if (! mkdir($fixtureDir, 0o755, true) && ! is_dir($fixtureDir)) {
        fwrite(STDERR, "Failed to create fixture dir: {$fixtureDir}\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------
// 1. Locked scenario constants.
// ---------------------------------------------------------------------

const SCENARIO_PERIOD_START = '2026-04-15';
const SCENARIO_PERIOD_END = '2026-05-14';
const SCENARIO_BULK_SETTLE_DATE = '2026-05-19';
const SCENARIO_ASN_OWN_IBAN = 'NL57ASNB0123456789';
const SCENARIO_ASN_OWN_NAME = 'KAARTHOUDER SYNTHETIC';
const SCENARIO_ICS_COUNTERPARTY = 'ICS-CARD';
const SCENARIO_STATEMENT_TOTAL_EUR_CENTS = 84_732;       // €847,32
const SCENARIO_OVERPAID_TOTAL_EUR_CENTS = 84_885;        // €848,85 (+€1,53)
const SCENARIO_UNDERPAID_TOTAL_EUR_CENTS = 84_514;       // €845,14 (−€2,18)
const SCENARIO_OVERPAID_DELTA_CENTS = 153;
const SCENARIO_UNDERPAID_DELTA_CENTS = -218;

// ICS transactions: 21 EUR-native rows + 2 FX rows (USD). Settled EUR
// amounts in cents sum to SCENARIO_STATEMENT_TOTAL_EUR_CENTS = 84732.
//
// Format per row: [tx_day, tx_month, book_day, book_month, merchant_with_country,
//                  settled_eur_cents, native_amount_cents_or_null, native_ccy_or_null,
//                  fx_rate_displayed_or_null]
//
// The two-letter country code at the end of merchant is the empirical
// Mijn ICS shape. Settled EUR amounts use the `Af` direction marker.
$icsTransactions = [
    ['15', 'apr', '17', 'apr', 'SPOTIFY AB                STOCKHOLM      SE', 999, null, null, null],
    ['16', 'apr', '17', 'apr', 'NETFLIX.COM               AMSTERDAM      NL', 1599, null, null, null],
    ['17', 'apr', '18', 'apr', 'JAGEX LIMITED             CAMBRIDGE      GB', 1099, null, null, null],
    ['18', 'apr', '19', 'apr', 'STEAM PURCHASE            LUXEMBOURG     LU', 5999, null, null, null],
    ['18', 'apr', '19', 'apr', 'NS REIZIGERS              UTRECHT        NL', 1245, null, null, null],
    ['19', 'apr', '20', 'apr', 'BOL.COM B.V.              UTRECHT        NL', 4750, null, null, null],
    ['20', 'apr', '21', 'apr', 'ALBERT HEIJN 1234         ZAANDAM        NL', 6192, null, null, null],
    ['21', 'apr', '22', 'apr', 'COOLBLUE                  ROTTERDAM      NL', 7995, null, null, null],
    ['22', 'apr', '23', 'apr', 'BLUE BOTTLE COFFEE        OAKLAND        US', 1207, 1299, 'USD', '1,07623'],
    ['23', 'apr', '24', 'apr', 'PATAGONIA INC             VENTURA        US', 6886, 7443, 'USD', '1,08089'],
    ['24', 'apr', '25', 'apr', 'IKEA AMSTERDAM            AMSTERDAM      NL', 8975, null, null, null],
    ['25', 'apr', '26', 'apr', 'BOOKS NL                  EINDHOVEN      NL', 2245, null, null, null],
    ['26', 'apr', '27', 'apr', 'JUMBO 4567                AMSTERDAM      NL', 3411, null, null, null],
    ['27', 'apr', '28', 'apr', 'SHELL 1122                AMSTERDAM      NL', 5025, null, null, null],
    ['28', 'apr', '29', 'apr', 'APPLE STORE               AMSTERDAM      NL', 2599, null, null, null],
    ['30', 'apr', '01', 'mei', 'HEMA WINKEL 0099          AMSTERDAM      NL', 1850, null, null, null],
    ['01', 'mei', '02', 'mei', 'GOOGLE PLAY               GOOGLE PLAYGB', 599, null, null, null],
    ['03', 'mei', '04', 'mei', 'AH TO GO                  AMSTERDAM      NL', 1175, null, null, null],
    ['05', 'mei', '06', 'mei', 'KLM ROYAL DUTCH AIR       AMSTELVEEN     NL', 8000, null, null, null],
    ['07', 'mei', '08', 'mei', 'ZEEMAN                    ALPHEN         NL', 875, null, null, null],
    ['09', 'mei', '10', 'mei', 'GAMMA BOUWMARKT           AMSTERDAM      NL', 3275, null, null, null],
    ['11', 'mei', '12', 'mei', 'MEDIAMARKT                AMSTERDAM      NL', 4990, null, null, null],
    ['13', 'mei', '14', 'mei', 'PRIMARK                   AMSTERDAM      NL', 3742, null, null, null],
];

// Sanity check: the synthesiser is the canonical source-of-truth.
$icsSum = array_sum(array_map(static fn (array $r): int => (int) $r[5], $icsTransactions));
if ($icsSum !== SCENARIO_STATEMENT_TOTAL_EUR_CENTS) {
    fwrite(STDERR, sprintf(
        "ICS transactions sum %d cents does not match locked statement total %d cents\n",
        $icsSum,
        SCENARIO_STATEMENT_TOTAL_EUR_CENTS,
    ));
    exit(1);
}
if (count($icsTransactions) !== 23) {
    fwrite(STDERR, sprintf(
        "ICS transaction count %d does not match locked count 23\n",
        count($icsTransactions),
    ));
    exit(1);
}

// ---------------------------------------------------------------------
// 2. Write scenario-1.md (the fixture record).
// ---------------------------------------------------------------------

$md = "# Phase 5 scenario-1 — synthesised cross-source fixture\n\n"
    ."**NOT anonymised from real user data.** Generated by\n"
    ."`scripts/synthesise_scenario_1_fixtures.php`. Re-run produces\n"
    ."byte-identical output (seeded `mt_srand(20260516)`).\n\n"
    ."## Empirical contract\n\n"
    ."- ICS statement period: 15 April 2026 → 14 May 2026\n"
    ."- ICS transaction count: 23 (21 EUR-native + 2 USD FX rows)\n"
    ."- ICS statement total (settled EUR): €847,32 (84732 cents)\n"
    ."- ASN bulk-iDEAL settlement date: 19 May 2026\n"
    ."- ASN own IBAN (synthetic): `NL57ASNB0123456789`\n"
    ."- ASN counterparty IBAN (synthetic): `ICS-CARD`\n"
    ."- PayPal own IBAN: `PAYPAL` (literal, scoped per-user)\n"
    ."- PayPal CSV row count: 14\n"
    ."- PayPal Reference-Txn-ID deterministic chain depth: 3 (parent + Bankstorting + Algemene-valutaomrekening pair)\n\n"
    ."## Reconciliation variants\n\n"
    ."| Variant       | Bulk-iDEAL amount (cents) | Delta (cents) | Expected `card_statement.state` | Expected `credit_carry` (cents) | Tolerance used     |\n"
    ."|---------------|---------------------------|---------------|--------------------------------|----------------------------------|--------------------|\n"
    ."| clean         | 84732                     | 0             | settled                         | 0                                | exact match        |\n"
    ."| overpaid      | 84885                     | +153          | overpaid                        | 153                              | amount_5eur        |\n"
    ."| underpaid     | 84514                     | −218          | partially_settled               | 0                                | amount_5eur        |\n\n"
    ."The base `asn-camt053.xml` carries the clean amount. The two overlay\n"
    ."JSON manifests (`scenario-1-overpaid.json`, `scenario-1-underpaid.json`)\n"
    ."carry the alternate bulk-iDEAL amount and the expected state-machine\n"
    ."outcomes for the resolver tests to overlay on top of the base XML.\n\n"
    ."## Locked features\n\n"
    ."- General Withdrawal NL hand-off (Phase 4 deferred item resolved by Phase 5):\n"
    ."  the PayPal CSV row at line 14 carries `Bankstorting naar PP-rekening`\n"
    ."  with the destination IBAN `NL57ASNB0123456789` inferable from the\n"
    ."  parent-row counterparty linkage. The Phase 5 PayPal funding\n"
    ."  resolver creates a deterministic `chain_link` of\n"
    ."  `kind='paypal_funding'` from the parent PayPal `transfer_out` to\n"
    ."  the matching ASN row.\n"
    ."- Deterministic Reference-Txn-ID chain at CSV lines 8–10\n"
    ."  (parent payment + Bankstorting funding sweep): the parent row\n"
    ."  reference appears verbatim in the child's `Reference Txn ID` cell.\n"
    ."- FX-conversion four-row chain at CSV lines 11–14\n"
    ."  (USD parent + EUR Bankstorting + EUR Algemene-valutaomrekening +\n"
    ."  USD Algemene-valutaomrekening), the empirical Phase 4 D-60(e) shape.\n\n"
    ."## Idempotency\n\n"
    ."Run `php scripts/synthesise_scenario_1_fixtures.php` twice in a row on a\n"
    ."clean filesystem; `git diff --exit-code Modules/Chains/tests/fixtures/scenario-1/`\n"
    ."returns no output.\n";

file_put_contents($fixtureDir.'/scenario-1.md', $md);

// ---------------------------------------------------------------------
// 3. Write the base (clean) asn-camt053.xml.
// ---------------------------------------------------------------------

$asnXml = makeAsnCamt053(SCENARIO_STATEMENT_TOTAL_EUR_CENTS);
file_put_contents($fixtureDir.'/asn-camt053.xml', $asnXml);

// ---------------------------------------------------------------------
// 4. Write the ICS PDF statement.
// ---------------------------------------------------------------------

$pdfBytes = makeIcsPdf($icsTransactions, SCENARIO_STATEMENT_TOTAL_EUR_CENTS);
file_put_contents($fixtureDir.'/ics-statement.pdf', $pdfBytes);

// ---------------------------------------------------------------------
// 5. Write the PayPal CSV.
// ---------------------------------------------------------------------

$csvBytes = makePaypalCsv();
file_put_contents($fixtureDir.'/paypal-activity.csv', $csvBytes);

// ---------------------------------------------------------------------
// 6. Write the two overlay manifests.
// ---------------------------------------------------------------------

$overpaid = [
    'variant' => 'overpaid',
    'bulk_settle_amount_minor' => SCENARIO_OVERPAID_TOTAL_EUR_CENTS,
    'delta_minor' => SCENARIO_OVERPAID_DELTA_CENTS,
    'expected_card_statement_state' => 'overpaid',
    'expected_credit_carry_minor' => SCENARIO_OVERPAID_DELTA_CENTS,
    'expected_chain_state' => 'confirmed',
    'tolerance_used' => 'amount_5eur',
];
$underpaid = [
    'variant' => 'underpaid',
    'bulk_settle_amount_minor' => SCENARIO_UNDERPAID_TOTAL_EUR_CENTS,
    'delta_minor' => SCENARIO_UNDERPAID_DELTA_CENTS,
    'expected_card_statement_state' => 'partially_settled',
    'expected_credit_carry_minor' => 0,
    'expected_chain_state' => 'confirmed',
    'tolerance_used' => 'amount_5eur',
];
file_put_contents(
    $fixtureDir.'/scenario-1-overpaid.json',
    json_encode($overpaid, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);
file_put_contents(
    $fixtureDir.'/scenario-1-underpaid.json',
    json_encode($underpaid, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

fwrite(
    STDERR,
    "Wrote 6 fixture files to {$fixtureDir}\n"
);
exit(0);

// =====================================================================
// Helper functions
// =====================================================================

/**
 * Builds the ASN CAMT.053 statement XML with one bulk-iDEAL debit entry.
 */
function makeAsnCamt053(int $amountCents): string
{
    $amountEur = sprintf('%.2f', $amountCents / 100);
    $msgId = 'SYNTHESISED-PHASE5-SCENARIO-1';
    $stmtId = 'STMT-SYNTH-2026-05';
    $bookDate = SCENARIO_BULK_SETTLE_DATE;
    $valueDate = SCENARIO_BULK_SETTLE_DATE;
    $creationDateTime = SCENARIO_BULK_SETTLE_DATE.'T08:00:00';

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <GrpHdr>
      <MsgId>{$msgId}</MsgId>
      <CreDtTm>{$creationDateTime}</CreDtTm>
    </GrpHdr>
    <Stmt>
      <Id>{$stmtId}</Id>
      <CreDtTm>{$creationDateTime}</CreDtTm>
      <Acct>
        <Id>
          <IBAN>NL57ASNB0123456789</IBAN>
        </Id>
        <Ccy>EUR</Ccy>
        <Ownr>
          <Nm>KAARTHOUDER SYNTHETIC</Nm>
        </Ownr>
      </Acct>
      <Bal>
        <Tp>
          <CdOrPrtry>
            <Cd>OPBD</Cd>
          </CdOrPrtry>
        </Tp>
        <Amt Ccy="EUR">2500.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Dt>
          <Dt>{$bookDate}</Dt>
        </Dt>
      </Bal>
      <Bal>
        <Tp>
          <CdOrPrtry>
            <Cd>CLBD</Cd>
          </CdOrPrtry>
        </Tp>
        <Amt Ccy="EUR">2500.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <Dt>
          <Dt>{$bookDate}</Dt>
        </Dt>
      </Bal>
      <Ntry>
        <Amt Ccy="EUR">{$amountEur}</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts>BOOK</Sts>
        <BookgDt>
          <Dt>{$bookDate}</Dt>
        </BookgDt>
        <ValDt>
          <Dt>{$valueDate}</Dt>
        </ValDt>
        <BkTxCd>
          <Prtry>
            <Cd>NIDA</Cd>
            <Issr>ASN</Issr>
          </Prtry>
        </BkTxCd>
        <NtryDtls>
          <TxDtls>
            <RltdPties>
              <Cdtr>
                <Nm>ICS Cards Nederland</Nm>
              </Cdtr>
              <CdtrAcct>
                <Id>
                  <Othr>
                    <Id>ICS-CARD</Id>
                  </Othr>
                </Id>
              </CdtrAcct>
            </RltdPties>
            <RmtInf>
              <Ustrd>iDEAL betaling ICS afschrift 2026-04</Ustrd>
            </RmtInf>
          </TxDtls>
        </NtryDtls>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>

XML;
}

/**
 * Builds a hand-crafted PDF 1.4 byte stream embedding the synthetic
 * Mijn ICS consumer-portal statement. Mirrors
 * `scripts/generate_tiny_ics_pdf.php`'s byte-stream technique.
 *
 * @param  list<array{0:string,1:string,2:string,3:string,4:string,5:int,6:int|null,7:string|null,8:string|null}>  $icsTransactions
 */
function makeIcsPdf(array $icsTransactions, int $totalCents): string
{
    $totalEurDutch = formatDutchEuroAmount($totalCents);

    // Statement header + cardholder banner + four-column summary + two-column limit block.
    $headerLines = [
        '15 mei 2026',
        'KAARTHOUDER ****-****-****-XXXX',
        'Uw Card met als laatste vier cijfers XXXX',
        'Periode 15 april 2026 - 14 mei 2026',
        'Vorig openstaand saldo Totaal ontvangen betalingen Totaal nieuwe uitgaven Nieuw openstaand saldo',
        '€ 0,00 Af € 0,00 Bij € '.$totalEurDutch.' Af € '.$totalEurDutch.' Af',
        'Bestedingslimiet Minimaal te betalen bedrag',
        '€ 5.000,00 € '.$totalEurDutch,
        '',
        'transactie boeking',
    ];

    $txLines = [];
    foreach ($icsTransactions as $row) {
        [$txDay, $txMonth, $bookDay, $bookMonth, $merchant, $settledCents, $nativeCents, $nativeCcy, $fxRate] = $row;
        $settledEurDutch = formatDutchAmountNoCurrency($settledCents);

        if ($nativeCents === null) {
            // EUR-native row: "txDay txMonth bookDay bookMonth merchant amount Af"
            $txLines[] = sprintf(
                '%s %s. %s %s. %s %s Af',
                $txDay,
                $txMonth,
                $bookDay,
                $bookMonth,
                $merchant,
                $settledEurDutch,
            );
        } else {
            // FX row: "txDay txMonth bookDay bookMonth merchant nativeAmt USD settledAmt Af"
            // followed by "Wisselkoers USD <rate>"
            $nativeDutch = formatDutchAmountNoCurrency($nativeCents);
            $txLines[] = sprintf(
                '%s %s. %s %s. %s %s %s %s Af',
                $txDay,
                $txMonth,
                $bookDay,
                $bookMonth,
                $merchant,
                $nativeDutch,
                $nativeCcy,
                $settledEurDutch,
            );
            $txLines[] = sprintf('Wisselkoers %s %s', $nativeCcy, $fxRate);
        }
    }

    $allLines = array_merge($headerLines, $txLines, [
        '',
        'Het minimaal te betalen bedrag ad EUR '.$totalEurDutch.' dient uiterlijk 28 mei 2026 op onze rekening te zijn bijgeschreven.',
    ]);

    // Build a PDF content stream that emits one Tj per line.
    // Use multi-page split so each page contains a chunk of lines and
    // keeps the page footprint manageable. For the synthetic case the
    // entire body fits in a single page comfortably.
    $content = 'BT /F1 9 Tf 36 760 Td';
    $first = true;
    foreach ($allLines as $line) {
        $escaped = escapePdfString($line);
        if ($first) {
            $content .= ' ('.$escaped.') Tj';
            $first = false;
        } else {
            $content .= ' 0 -11 Td ('.$escaped.') Tj';
        }
    }
    $content .= ' ET';
    $contentLen = strlen($content);

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 1008] '
            .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => "<< /Length {$contentLen} >>\nstream\n{$content}\nendstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $n => $obj) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "{$n} 0 obj\n{$obj}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= 'trailer'."\n";
    $pdf .= '<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n";
    $pdf .= 'startxref'."\n";
    $pdf .= $xrefOffset."\n";
    $pdf .= '%%EOF'."\n";

    return $pdf;
}

/**
 * Builds the synthetic PayPal "Activity Download" CSV in the NL profile.
 *
 * 14 rows total. The header row carries the 7-token discriminator
 * (Datum, Tijd, Tijdzone, Omschrijving, Valuta, Transactiereferentie,
 * Reference Txn ID). The body rows mirror the empirical
 * Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv shape.
 */
function makePaypalCsv(): string
{
    $header = [
        'Datum', 'Tijd', 'Tijdzone', 'Omschrijving', 'Valuta',
        'Bruto ', 'Kosten ', 'Netto', 'Saldo', 'Transactiereferentie',
        'Van e-mailadres', 'Naam', 'Naam bank', 'Bankrekening',
        'Verzendkosten', 'Btw', 'Factuurreferentie', 'Reference Txn ID',
    ];

    // 13 data rows. Refs are O-PHASE5-<seq>; the chain uses parent IDs
    // verbatim in `Reference Txn ID` so the rollup walker recognises
    // them as parent-child links.
    $rows = [];

    // Standalone EUR Express Checkout Payments (5).
    $rows[] = paypalRow('4/22/2026', '08:30:00', 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker', '-15,99', 'O-PHASE5-0000000000000001', 'kaarthouder@example.test', 'Netflix.com', '518803381922610001', 'O-PHASE5-0000000000000002');
    $rows[] = paypalRow('4/22/2026', '08:30:00', 'Bankstorting naar PP-rekening', '15,99', 'O-PHASE5-0000000000000003', '', '', '518803381922610001', 'O-PHASE5-0000000000000001');

    $rows[] = paypalRow('4/23/2026', '11:00:00', 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker', '-9,99', 'O-PHASE5-0000000000000004', 'kaarthouder@example.test', 'Spotify AB', '518803381922610002', 'O-PHASE5-0000000000000005');
    $rows[] = paypalRow('4/23/2026', '11:00:00', 'Bankstorting naar PP-rekening', '9,99', 'O-PHASE5-0000000000000006', '', '', '518803381922610002', 'O-PHASE5-0000000000000004');

    // Deterministic Reference-Txn-ID chain (parent + Bankstorting close-out
    // whose memo carries the inferable destination IBAN NL57ASNB0123456789).
    // The "Naam bank" cell holds the IBAN to mirror the PayPal-to-bank hand-off.
    $rows[] = paypalRow('4/30/2026', '14:15:00', 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker', '-29,50', 'O-PHASE5-0000000000000007', 'kaarthouder@example.test', 'Jagex Limited', '518803381922610003', 'O-PHASE5-0000000000000008');
    $rows[] = paypalRow('4/30/2026', '14:15:00', 'Bankstorting naar PP-rekening', '29,50', 'O-PHASE5-0000000000000009', '', 'Bankstorting', '518803381922610003', 'O-PHASE5-0000000000000007', bankAccount: 'NL57ASNB0123456789');

    // FX-conversion 4-row chain: USD parent + EUR Bankstorting +
    // EUR Algemene-valutaomrekening + USD Algemene-valutaomrekening.
    // All four rows share the parent's reference in their Reference Txn ID.
    $rows[] = paypalUsdRow('5/2/2026', '09:00:00', 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker', '-22,50', 'USD', 'O-PHASE5-0000000000000010', 'kaarthouder@example.test', 'Cloudflare Inc', '518803381922610004', 'O-PHASE5-0000000000000011');
    $rows[] = paypalRow('5/2/2026', '09:00:00', 'Bankstorting naar PP-rekening', '20,80', 'O-PHASE5-0000000000000012', '', '', '518803381922610004', 'O-PHASE5-0000000000000010');
    $rows[] = paypalRow('5/2/2026', '09:00:00', 'Algemene valutaomrekening', '20,80', 'O-PHASE5-0000000000000013', '', '', '518803381922610004', 'O-PHASE5-0000000000000010');
    $rows[] = paypalUsdRow('5/2/2026', '09:00:00', 'Algemene valutaomrekening', '-22,50', 'USD', 'O-PHASE5-0000000000000014', '', '', '518803381922610004', 'O-PHASE5-0000000000000010');

    // Three more standalone EUR payments to reach 14 total rows
    // (header + 13 body).
    $rows[] = paypalRow('5/5/2026', '12:45:00', 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker', '-4,99', 'O-PHASE5-0000000000000015', 'kaarthouder@example.test', 'Google Cloud EMEA Limited', '518803381922610005', 'O-PHASE5-0000000000000016');
    $rows[] = paypalRow('5/5/2026', '12:45:00', 'Bankstorting naar PP-rekening', '4,99', 'O-PHASE5-0000000000000017', '', '', '518803381922610005', 'O-PHASE5-0000000000000015');
    $rows[] = paypalRow('5/8/2026', '07:20:00', 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker', '-12,99', 'O-PHASE5-0000000000000018', 'kaarthouder@example.test', 'Adobe Systems Software', '518803381922610006', 'O-PHASE5-0000000000000019');

    if (count($rows) !== 13) {
        fwrite(STDERR, sprintf("PayPal CSV body row count %d (expected 13)\n", count($rows)));
        exit(1);
    }

    // Emit CSV with the same quoting / delimiter shape as the empirical
    // fixture. Use fputcsv against a php://temp stream so quoting rules
    // match league/csv's reader expectations.
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        fwrite(STDERR, "Failed to open php://temp for CSV writing\n");
        exit(1);
    }
    fputcsv($stream, $header, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($stream, $row, ',', '"', '\\');
    }
    rewind($stream);
    $csv = (string) stream_get_contents($stream);
    fclose($stream);

    return $csv;
}

/**
 * @return list<string>
 */
function paypalRow(
    string $date,
    string $time,
    string $omschrijving,
    string $bruto,
    string $transactionRef,
    string $email,
    string $naam,
    string $factuurref,
    string $referenceTxnId,
    string $bankAccount = '',
): array {
    return [
        $date,
        $time,
        'Europe/Berlin',
        $omschrijving,
        'EUR',
        $bruto,
        '0,00',
        $bruto,
        '0,00',
        $transactionRef,
        $email,
        $naam,
        '',
        $bankAccount,
        '0,00',
        '0,00',
        $factuurref,
        $referenceTxnId,
    ];
}

/**
 * @return list<string>
 */
function paypalUsdRow(
    string $date,
    string $time,
    string $omschrijving,
    string $bruto,
    string $currency,
    string $transactionRef,
    string $email,
    string $naam,
    string $factuurref,
    string $referenceTxnId,
): array {
    return [
        $date,
        $time,
        'Europe/Berlin',
        $omschrijving,
        $currency,
        $bruto,
        '0,00',
        $bruto,
        '0,00',
        $transactionRef,
        $email,
        $naam,
        '',
        '',
        '0,00',
        '0,00',
        '',
        $referenceTxnId,
    ];
}

/**
 * Formats a cents value as the Dutch-locale euro amount string with the
 * thousands separator `.` and the decimal separator `,` (e.g. 5000 ->
 * "50,00", 500000 -> "5.000,00").
 */
function formatDutchAmountNoCurrency(int $cents): string
{
    $whole = intdiv($cents, 100);
    $frac = $cents % 100;
    $wholeStr = number_format((float) $whole, 0, ',', '.');

    return sprintf('%s,%02d', $wholeStr, $frac);
}

function formatDutchEuroAmount(int $cents): string
{
    return formatDutchAmountNoCurrency($cents);
}

/**
 * Escapes literal-parens + backslashes inside a PDF text string per the
 * PDF spec. Multibyte glyphs not in WinAnsi fall back to their question-
 * mark sentinel — the synthetic content uses only basic-ASCII + €.
 */
function escapePdfString(string $s): string
{
    // Map € (U+20AC) to its WinAnsiEncoding byte (0x80) so the embedded
    // Helvetica font can render it as €. pdftotext decodes the byte
    // back to U+20AC under -enc UTF-8.
    $s = str_replace("\xE2\x82\xAC", "\x80", $s);

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $s,
    );
}
