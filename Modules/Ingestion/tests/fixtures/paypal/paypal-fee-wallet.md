# PayPal CSV — the fee fixtures

`paypal-fee-wallet.csv` and `paypal-fee-on-a-converted-payment.csv` are
**constructed**, not redacted from a real export. Every empirically redacted
PayPal fixture in this directory — `paypal-sample-1.csv` and the three files
derived from its shapes — carries `"0,00"` in the `Kosten` column on every one
of its rows, which is exactly why a wallet booking `Bruto` passed for four
months. A file whose fee is always nothing cannot tell a reader of `Bruto` from
a reader of `Netto`.

The column layout, the header spellings (including the trailing space inside
`"Bruto "` and `"Kosten "`), the `M/D/YYYY` dates, the NL event-type strings and
the `O-<17-digit>` reference shape are all copied verbatim from
`paypal-sample-1.csv`; only the amounts are authored. See
[`paypal-sample-1.md`](paypal-sample-1.md) for the empirical record those
shapes come from.

## `paypal-fee-wallet.csv`

A single-currency (EUR) wallet, opening at zero. `Saldo` steps by `Netto` on
every row, as PayPal's own does.

| Ref | Event type | Bruto | Kosten | Netto | Saldo | What it covers |
|---|---|---:|---:|---:|---:|---|
| `O-…101` | `Algemene kaartstorting` | `100,00` | `-3,49` | `96,51` | `96,51` | The arithmetic in the round: a credit whose fee points the other way. A fee derived from the payment's sign would read `+3,49`. |
| `O-…102` | `Express Checkout-betaling` | `-25,00` | `-0,50` | `-25,50` | `71,01` | A debit and its fee pointing the same way — the case a sign bug survives. |
| `O-…103` | `Vooraf goedgekeurde betaling – …` | `-10,00` | `0,00` | `-10,00` | `61,01` | The common case: no fee row at all. |
| `O-…104` | `Express Checkout-betaling` | `-40,00` | `1,25` | `-38,75` | `22,26` | A returned fee on a negative gross. `abs()` gets this one right and the row above it wrong; mirroring the gross gets this one wrong. |
| `O-…105` | `Express Checkout-betaling` | `-5,00` | `-0,60` | `-5,60` | `16,66` | Two identical charges on one day… |
| `O-…106` | `Express Checkout-betaling` | `-5,00` | `-0,60` | `-5,60` | `11,06` | …whose two fee rows agree in every fingerprint column but the occurrence ordinal. |

Totals: `Bruto` `15,00`, `Kosten` `-3,94`, `Netto` `11,06`. The adapter emits
**11** DTOs — six payments and five fees — and publishes `11,06` as the closing
balance. Before the fee became a row it emitted six and published `15,00`.

No row carries a `Reference Txn ID`, so nothing in this file is a child and the
rollup's parent/child walk is deliberately not what is under test here.

## `paypal-fee-on-a-converted-payment.csv`

A GBP wallet paying a USD merchant, copied from the four-row conversion shape in
`paypal-gbp-wallet.csv` with a `-0,50` USD fee added to the parent. The fee is
stated in USD and nothing in the file restates it in GBP, so the fee row carries
no settled leg and the statement publishes no closing balance — the adapter's
existing rule for a file naming two denominations.
