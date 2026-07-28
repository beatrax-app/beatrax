<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Tax Export {{ $year }}</title>
    <style>
        /* CSS 2.1 only — dompdf does not support Flexbox or CSS Grid.
           Table-only layout. No Tailwind, no external stylesheets.
           isRemoteEnabled=false; all styles are inline here. */

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10pt;
            color: #1e293b;
            margin: 0;
            padding: 0;
        }

        h1 {
            font-size: 16pt;
            font-weight: bold;
            color: #0f172a;
            margin: 0 0 4pt 0;
            padding: 0;
        }

        h2 {
            font-size: 11pt;
            font-weight: bold;
            color: #1e293b;
            margin: 12pt 0 4pt 0;
            padding: 0;
            border-bottom: 1pt solid #cbd5e1;
        }

        p {
            margin: 2pt 0;
            padding: 0;
        }

        .summary-block {
            margin-bottom: 16pt;
            padding: 8pt;
            background-color: #f8fafc;
            border: 1pt solid #e2e8f0;
        }

        .summary-label {
            font-weight: bold;
            color: #475569;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4pt;
        }

        /* th is included so the row labels keep the cell metrics they had as
           td, and text-align resets the centring a th would otherwise take. */
        .summary-table td,
        .summary-table th {
            padding: 3pt 6pt;
            font-size: 10pt;
            text-align: left;
        }

        table.tx-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12pt;
        }

        table.tx-table th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 8pt;
            font-weight: bold;
            text-align: left;
            padding: 4pt 5pt;
            border-bottom: 1pt solid #cbd5e1;
        }

        table.tx-table td {
            font-size: 8.5pt;
            padding: 3pt 5pt;
            border-bottom: 1pt solid #f1f5f9;
            vertical-align: top;
        }

        table.tx-table tr {
            page-break-inside: avoid;
        }

        table.tx-table .amount {
            text-align: right;
            white-space: nowrap;
        }

        .subtotal-row td {
            font-weight: bold;
            background-color: #f8fafc;
            border-top: 1pt solid #94a3b8;
            padding: 4pt 5pt;
        }

        .no-cat-section h2 {
            color: #64748b;
        }

        .footer {
            margin-top: 20pt;
            font-size: 7pt;
            color: #94a3b8;
            border-top: 1pt solid #e2e8f0;
            padding-top: 4pt;
        }
    </style>
</head>
<body>

<h1>Tax Export {{ $year }}</h1>

{{-- Summary block --}}
<div class="summary-block">
    {{-- Two label/value pairs per row, so the header for each value is the
         cell to its left, not a column heading: th scope="row" says exactly
         that. The labels were already rendered bold, which is what a th is. --}}
    <table class="summary-table">
        <tr>
            <th scope="row" class="summary-label">Year:</th>
            <td>{{ $data->year }}</td>
            <th scope="row" class="summary-label">Total items:</th>
            <td>{{ $data->itemCount }}</td>
        </tr>
        <tr>
            <th scope="row" class="summary-label">Total deductions:</th>
            <td>&euro; {{ number_format($data->deductionsTotalMinor / 100, 2, '.', ',') }}</td>
            <th scope="row" class="summary-label">Total income:</th>
            <td>&euro; {{ number_format($data->incomeTotalMinor / 100, 2, '.', ',') }}</td>
        </tr>
    </table>
</div>

@if($data->itemCount === 0)
    <p>No tax-tagged transactions for {{ $data->year }}.</p>
@else
    {{-- One table per deduction category --}}
    @foreach($data->categories as $category)
        @php
            /** @var array<string, mixed> $category */
            $catName = is_string($category['name'] ?? null) ? $category['name'] : null;
            $isNoCategory = $catName === null;
            $subtotalMinor = is_numeric($category['subtotalMinor'] ?? 0) ? (int) $category['subtotalMinor'] : 0;
            $rows = is_array($category['rows'] ?? null) ? $category['rows'] : [];
        @endphp

        <div @class(['no-cat-section' => $isNoCategory])>
            {{-- {{ }} already escapes — an extra e() double-encodes & < ' (WR-10). --}}
            <h2>{{ $isNoCategory ? 'Uncategorised' : $catName }}</h2>

            <table class="tx-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Counterparty</th>
                        <th>Description</th>
                        <th>Note</th>
                        <th class="amount">Amount (EUR)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            /** @var array<string, mixed> $row */
                            $bookedAt = is_string($row['bookedAt'] ?? null) ? substr($row['bookedAt'], 0, 10) : '';
                            $counterparty = is_string($row['counterpartyName'] ?? null) ? $row['counterpartyName'] : '';
                            $description = is_string($row['description'] ?? null) ? $row['description'] : '';
                            $note = is_string($row['note'] ?? null) ? $row['note'] : '';
                            $settledMinor = is_numeric($row['settledAmountMinor'] ?? 0) ? (int) $row['settledAmountMinor'] : 0;
                            $amountStr = number_format(abs($settledMinor) / 100, 2, '.', ',');
                        @endphp
                        <tr>
                            <td>{{ $bookedAt }}</td>
                            <td>{{ $counterparty }}</td>
                            <td>{{ $description }}</td>
                            <td>{{ $note }}</td>
                            <td class="amount">&euro; {{ $amountStr }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal-row">
                        <td colspan="4">Subtotal</td>
                        <td class="amount">&euro; {{ number_format(abs($subtotalMinor) / 100, 2, '.', ',') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endforeach
@endif

<div class="footer">
    Generated by beatrax &mdash; local-only personal finance &mdash; {{ $year }}
</div>

</body>
</html>
