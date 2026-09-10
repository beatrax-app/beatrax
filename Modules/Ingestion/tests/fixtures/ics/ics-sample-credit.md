# ICS PDF — a statement that closed in credit

`ics-sample-credit.txt` is a **synthesised** `pdftotext -layout`
extraction in the layout `ics-sample-1.txt` records empirically. It is
not a real export and carries no real cardholder data; every field is
either a placeholder (`KLANTNUMMER`, `KAARTHOUDER`, `XXXX`,
`NL95BANK0000000000`) or a figure chosen to make the summary block
arithmetic self-evident.

It exists because the shipped empirical statement prints
`Af / Bij / Af / Af` across its four summary columns — exactly the
fixed per-column sign the adapter used to apply — so no fixture in the
repository could tell a direction that was read from one that was
assumed.

## What this statement says

The card was paid off past zero the month before, so it opens with a
credit rather than a debt and closes still in credit after a single
purchase:

| Column                        | Printed        | Stored   |
| ----------------------------- | -------------- | -------- |
| `Vorig openstaand saldo`      | `€ 93,04 Bij`  | `+9304`  |
| `Totaal ontvangen betalingen` | `€ 0,00 Bij`   | `0`      |
| `Totaal nieuwe uitgaven`      | `€ 43,71 Af`   | `-4371`  |
| `Nieuw openstaand saldo`      | `€ 49,33 Bij`  | `+4933`  |

The four figures balance: `9304 + 0 - 4371 = 4933`. That identity is
what the test asserts, alongside the two `Bij` balances, because it
holds for any statement and fails for any column whose sign was decided
by which column it is rather than by the marker printed beside it.

## Other tokens

- One transaction row — `OVHcloud`, `€ 43,71 Af`, transaction day
  20 feb., booking day 21 feb. — so `period_start` and `period_end`
  are both `2026-02-20`.
- `Volgnummer` `3`, statement date 15 maart 2026, so a `feb.` row
  resolves to 2026 and not to 2025.
- `Het minimaal te betalen bedrag ad € 0,00 verwachten wij voor
  6 april 2026` — the printed deadline, read rather than derived.
- `Bestedingslimiet € 2.500,00` and `Minimaal te betalen bedrag
  € 0,00`, the informational two-column block.
