# Feature parity with Dyme — gap analysis

A working list of features beatrax is still missing to reach parity with
[Dyme](https://dyme.app/en), the Dutch money-management app. Use it as a
roadmap input, not a commitment.

**Read this caveat first.** Dyme and beatrax start from opposite premises:

| | Dyme | beatrax |
|---|---|---|
| Data source | Live **bank link** (PSD2 / open banking, via Tink) | **Statement files** you export (CSV / CAMT.053 / MT940 / PDF) + email receipts |
| Hosting | Cloud SaaS, mobile app | **Local-only** desktop app (NativePHP) / self-hosted server |
| Business model | Free app + **paid services** (cancellation, contract switching) + Premium tiers | Source-available software, no service layer, no telemetry |

So some Dyme "features" are **deliberate non-goals** for beatrax (live bank
linking, paid cancellation-as-a-service, contract-negotiation-as-a-service) —
they conflict with the privacy-first, software-not-service stance. Those are
listed separately at the bottom rather than treated as gaps to close.

## Scorecard

Legend: ✅ have · 🟡 partial · 🟦 planned (v1.2) · ❌ missing · ⛔ deliberate non-goal

| Dyme capability | beatrax | Notes |
|---|---|---|
| Import transactions | ✅ | Files + email, not a live bank link. Multi-country CAMT.053/MT940/CSV now. |
| Auto-categorisation + custom categories | ✅ | Categorization module, category tree, triage, rules. |
| Fixed monthly expenses & income overview | ✅ | Recurring module + Fixed Payments view; income detector. |
| Subscription detection / recurring overview | ✅ | Recurring detection; **Subscription Drift Watch** adds price-creep. 🟦 |
| Spending insight by category | ✅ | "This month at a glance" + transactions/forecast. |
| Balance-over-time graph / forecast | ✅ | Forecasting module (cash-flow, scenarios, what-if). |
| Dark mode | ✅ | Supported. |
| Multi-account view | ✅ | Ledger across bank/card/PayPal + funding **chains** (unique to beatrax). |
| Multi-currency | ✅ | First-class; Dyme is EUR-centric. |
| **Budgets** (weekly/monthly, progress) | 🟦 | Planned: **Category Budgets** (v1.2). |
| **Cancel / support / cheaper-deal links** | 🟦 | Planned: **support-resource profiles** (links, not in-app cancellation). |
| Backup / data portability | 🟦 | Planned: **Encrypted Backup & Restore** (v1.2). |
| **Savings goals** | ❌ | Set a target, track progress, project completion date. |
| **Savings / goal accounts** | 🟡 | Accounts exist; no goal-linked savings pots. |
| **Notifications & reminders** | 🟡 | Have Drift Alerts; missing payment-due reminders, daily/weekly summary, "savings opportunity" nudges. |
| **Month-over-month comparison** | 🟡 | Have per-month view; no explicit MoM / trend comparison surface. |
| **Net-worth / total-balance roll-up** | 🟡 | Per-account balances exist; no single net-worth-over-time figure. |
| **Manual / cash transactions ("cash book")** | ❌ | Add a cash expense by hand; everything is import-derived today. |
| **Mobile access** | 🟡 | Desktop app; server deploy enables mobile browser. No native app / PWA polish. |
| **Quick unlock (PIN / biometric)** | 🟡 | Auth + passkeys exist; no app-lock PIN/biometric on resume. |
| In-app one-click subscription **cancellation** | ⛔/🟡 | We surface official cancel links; we do not act as a cancellation agent. |
| Contract switching / negotiation **as a service** | ⛔ | Out of scope (a paid human service, not software). |
| Live bank linking (PSD2 / open banking) | ⛔ | Conflicts with local-only/privacy stance. |

## Missing features to consider (ranked)

### High value, fits the product
1. **Savings goals** — name a goal, target amount + date, track contributions
   (a transfer to a savings account / pot), and project the finish date off
   the Forecasting engine. Pairs naturally with Category Budgets.
2. **Notifications & reminders** — beatrax already has the Drift Alerts
   surface and a queue worker. Extend to: upcoming fixed-payment reminders,
   a daily/weekly "this is your position" digest, and "you could cancel /
   switch this" nudges driven by the support-resource corpus. Local-only
   delivery (desktop tray / in-app), no push servers.
3. **Net-worth roll-up** — one figure across all accounts, trend line over
   time, reusing the balance/forecast plumbing. Cheap, high perceived value.
4. **Month-over-month / trend comparison** — "groceries up €40 vs last
   month", category deltas, rolling 3/6/12-month spark lines.

### Medium value
5. **Manual / cash transactions** — a "cash book" so non-bank spending can be
   recorded; respects the single-canonical-ledger model (a manual source
   adapter feeding the same Transaction pipeline).
6. **Mobile experience** — with server-deploy landing, invest in a responsive
   / installable-PWA pass so the self-hosted instance is pleasant on a phone.
7. **Quick app-lock** — PIN / OS biometric to unlock on resume (desktop +
   self-host), distinct from the account login.

### Lower / situational
8. **"You could save here" insights** — surface the cheaper-deal / retention
   links from the support-resource corpus as proactive savings suggestions
   (the software-only analogue of Dyme's contract-switching service).
9. **Goal-linked savings pots / envelopes** — virtual sub-balances within an
   account.

## Deliberate non-goals (divergences from Dyme, by design)

- **Live bank linking (PSD2/open banking).** beatrax is statement-import +
  email-scan by design; financial data never leaves the machine.
- **Paid cancellation-as-a-service.** We show *how/where* to cancel (official
  links); we do not act on the user's behalf.
- **Contract negotiation/switching as a paid service.** Out of scope — that's
  a human/brokerage service, not local software.
- **Cloud sync / accounts / telemetry.** Local-only is a core constraint.

## Already closing in v1.2

Budgets, Subscription Drift Watch, Encrypted Backup & Restore, and the
merchant/government **support-resource profiles** (cancel/support/help links)
each close part of the gap above.

## Sources

- [Dyme — money-saving app overview](https://dyme.app/en)
- [Dyme — recurring costs overview](https://dyme.app/en/get-your-money-together)
- [Dyme — save money / contract switching](https://dyme.app/en/save-money)
- [Dyme update — budgeting, savings accounts, dark mode, PIN](https://dyme.app/en/blog/about-us/dyme-update-budgeting-and-more)
- [Dyme update — auto-categorise, Premium, cash book](https://dyme.app/en/blog/about-us/dyme-update-cash-book-and-more)
- [Dyme × Tink — subscription service / bank linking](https://tink.com/press/dyme-tink/)
- [Dyme on Google Play (feature list)](https://play.google.com/store/apps/details?id=com.dyme.dyme&hl=en)
