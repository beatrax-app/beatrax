# Store listing copy

A **draft for the owner to review, edit and publish**. Nothing on this page is
submitted by anything in this repository, and no pipeline reads it.

[The store-submission runbook](store-submission.md) is where each *declaration*
a submission form asks for is derived. This page is the other half: the prose a
reader sees, and the evidence each sentence rests on. Read that page first —
its **"What a listing may not say"** section is binding here, and so is
[`F8-R26`](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md#acceptance-criteria):
a listing may not claim a protection the product does not provide, may not
describe a smaller outbound surface than the catalogue, and may not describe a
capability that does not work on that platform.

## The rule every sentence here clears

A listing is product copy, and product copy in Beatrax is bound by
[DES-R8](https://github.com/beatrax-app/spec/blob/main/60-brand/README.md#the-des-r-namespace):
copy never claims a protection the implementation does not provide. A store
page is the most public label the product has, and a label asserting something
the facts can contradict is a defect here rather than a rounding error.

So each claim below traces to a file, a test or a requirement, and the trace is
written down. **A claim that could not be traced was removed rather than
softened** — the list of what came out is at
[Claims that were cut](#claims-that-were-cut), and it is as useful to read as
the copy is.

Three statements the application is already required to make to its own users
([G1-R14, G1-R15, G1-R16](https://github.com/beatrax-app/spec/blob/main/10-functional/features/g-ux/g1-privacy.md))
constrain every long description that mentions sync or encryption, and they
appear in each one:

- at-rest encryption leaves amounts, dates and the search index readable;
- a relay sees metadata — sizes, timing, which device identifiers exchange;
- a paired device is trusted, and revoking one is not retroactive.

The copy discloses at least as much as the settings screen does. The shipped
string `sync::devices.encrypted_at_rest_scope` also names the reader's **own**
account name and IBAN and the merchant names still in plain text; a listing that
disclosed less than the screen would be an under-disclosure, which is the defect
`DevicesAndSyncEncryptionUiTest` exists to catch.

**English only.** Translation into the other 25 interface languages is a
separate decision and a separate piece of work.

## Which channels this covers

All four store listings are in scope and every one of them is **additive**:
direct download is not retired by any of them
([F8-R1, F8-R27](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md#acceptance-criteria),
[ADR-0032](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0032-all-four-stores-additive-to-direct-download.md)).
Five bodies of copy follow, because the download page is a listing too and is
the one the other four must not contradict.

| Channel | Artefact | Can it be submitted today? |
|---|---|---|
| Google Play | AAB from Bifrost, APK beside it | Yes, once the listing and Play account work is done |
| Apple App Store (iOS) | IPA from Bifrost | Yes. It is the only route onto iPhone |
| Mac App Store | `.pkg` from the `mas` lane | **No** — the Mac Installer Distribution certificate is not held, and the MAS provisioning profile secret is not set |
| Microsoft Store | the existing signed NSIS installer, submitted as a versioned URL | **No** — needs a Company Partner Center account, and the privacy policy below |
| Direct download | `.dmg`, `.exe`/`.msi`, `.AppImage`/`.deb`, `.apk`, checksums, signed manifests | Shipping today |

Two preconditions apply to all of them.

**This copy describes v2.0.** Sync, the rules engine, splits, reconciliation,
budgets, reports and the notification inbox are merged but were not in the last
tag. Copy naming them must not be published against a build that predates them.

**France cannot be a release territory** until the ANSSI encryption declaration
is filed and its code ships in the bundle
([F8-R11](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md#acceptance-criteria);
[the mobile release runbook](mobile-release.md) carries the paperwork).

## The field limits each draft was written to

Written to the limit, then counted. Every count below is of the copy as it
appears on this page.

| Store | Field | Limit | Source |
|---|---|---|---|
| Google Play | App name | 30 | Play Console Help, *Add preview assets to showcase your app* (`support.google.com/googleplay/android-developer/answer/9859152`) |
| Google Play | Short description | 80 | same |
| Google Play | Full description | 4000 | same |
| Google Play | Tags | 5, from a fixed list | Play Console Help, *Choose a category and tags* (`answer/9859673`) |
| App Store · Mac App Store | Name | 30 | App Store Connect Help, *App information* reference |
| App Store · Mac App Store | Subtitle | 30 | same |
| App Store · Mac App Store | Promotional text | 170 | App Store Connect Help, *Platform version information* reference |
| App Store · Mac App Store | Description | 4000 | same |
| App Store · Mac App Store | Keywords | 100 bytes | same |
| App Store · Mac App Store | What's New | 4000 | same |
| Microsoft Store | Description | 10000 | Microsoft Learn, *Add and edit Store listing info for MSI/EXE app* |
| Microsoft Store | Short description | 1000, first 270 shown | same |
| Microsoft Store | Product features | 200 each, 20 max | same |
| Microsoft Store | What's new | 1500 | same |
| Microsoft Store | Additional system requirements | 200 each, 11 max | same |

Two notes on that table. Apple counts keywords in **bytes**, not characters, and
a non-ASCII character therefore costs more than one — an English keyword list is
the same either way, a translated one is not. And the MSI/EXE listing
documentation describes **no search-terms field**; if Partner Center offers one,
the App Store keyword list below is the right starting point.

What each block below actually measures, so an edit can be checked against
the same numbers:

| Field | Written | Limit |
|---|---|---|
| Play app name | 25 characters | 30 |
| Play short description | 76 characters | 80 |
| Play full description | 3994 characters | 4000 |
| iOS name | 25 characters | 30 |
| iOS subtitle | 27 characters | 30 |
| iOS promotional text | 164 characters | 170 |
| iOS keywords | 84 bytes | 100 bytes |
| iOS description | 3999 characters | 4000 |
| Mac name | 25 characters | 30 |
| Mac subtitle | 23 characters | 30 |
| Mac promotional text | 161 characters | 170 |
| Mac keywords | 84 bytes | 100 bytes |
| Mac description | 3998 characters | 4000 |
| Windows product name | 7 characters | 256 |
| Windows short description | 270 characters | 270 shown |
| Windows description | 5691 characters | 10000 |

Re-read the store's own page before submitting. These are the limits as
published at the time of writing, and a description that overflows is rejected
rather than truncated.

## Google Play

Category **Finance**. Up to five tags, chosen from Play's own fixed list —
these are not free text, so pick the closest available to *personal finance*,
*budgeting*, *expense tracker*, *money manager* and *banking* rather than
submitting the words below verbatim.

### Play app name

```text
Beatrax: Personal Finance
```

### Play short description

```text
Read the statements your bank already exports. One calm picture, no account.
```

### Play full description

```text
Beatrax keeps your money history in a file on your own device. There is no Beatrax account, no Beatrax service and nothing to sign in to.

WHAT IT READS

It reads the statements your bank already gives you, not your banking password: CAMT.053 and MT940, CSV with ready-made profiles for N26, Revolut, ING (Netherlands) and ASN, PayPal exports, ICS card PDFs, and cash you type in. You say which format a file is; every import is previewed, and re-importing one changes nothing. Then it categorises with rules you write, names the counterparty behind an IBAN once for every transaction sharing it, and links a card purchase to the settlement that paid it — alongside envelope budgets, goals and pots, splits and reconciliation, recurring and unusual-charge alerts, a forecast, a bills calendar, reports, search and multi-currency. In 26 languages.

YOUR DEVICES, NOT A SERVER

Sync is optional and off until you pair two devices. You pair by scanning the code the other shows, or typing it in; both then show the same six words to compare before anything moves. Paired devices exchange changes directly over your own network, in a session each end authenticates and encrypts. No Beatrax service takes part.

Four things worth knowing:

• Sync is started, not scheduled. Both devices must be open on the same network at once, and a phone has no background sync: your app lock holds the key that signs your changes, and a background task cannot reach it.
• A relay — one your devices set up, or one you nominate — is there so two can finish pairing and exchange keys. It does not carry your transactions.
• A relay sees metadata: sizes, timing, and which device identifiers exchange traffic. Traffic analysis is not defended against.
• A paired device is trusted. Removing one mints a new key from that point on and re-wraps it to the devices you kept; it does not un-see what was already synced.

Turning sync on cannot be undone: it needs an app lock, it encrypts your data from then on, and neither can be switched off again.

ENCRYPTION, PRECISELY

At-rest encryption is off until you turn it on, and mandatory once you sync. It covers notes, transaction descriptions, and the names and IBANs of who you pay. It does not cover amounts, dates, or your own account name and IBAN. The search index keeps a readable copy of who you pay, your descriptions and your notes, so search keeps working, and some merchant names stay in plain text elsewhere in the database file. It raises the cost of casual access to a copied file; it is not a defence against somebody who has the file and time.

The key is released by your app lock: a PIN you choose, optionally with biometrics. Lose that passphrase with no backup and no other paired device and your data cannot be recovered — the same property that means nobody else can be handed it.

WHAT IT SENDS

With no optional feature on, this app makes no network connection at all. It carries no update check of its own; Google Play installs updates.

Two features reach outside your phone, both off until you switch them on, each talking to a service you already hold credentials for: open banking for ASN and SNS accounts through Enable Banking, with your own API key — it reads accounts, balances and transactions, and cannot ask to move money: there is no field for it — and exchange rates from the European Central Bank or Frankfurter, with a bundled set used until then.

No analytics, telemetry, crash reporting or advertising, and Android's backup system does not copy Beatrax's data off your phone. Connecting a mailbox for receipts is done on the desktop; what it finds reaches the phone by sync.

Beatrax is also a direct download for macOS, Windows, Linux and Android, with checksums and a signed manifest each release; this listing adds to that channel, it does not replace it. No subscription, no in-app purchases. The source is published under the Hippocratic License 3.0 — source-available, not OSI open source. Requires Android 13 or later.
```

## Apple App Store — iPhone and iPad

Primary category **Finance**, secondary **Productivity**.

The iOS draft differs from Play in three places, each of them
[`F8-R26`](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md#acceptance-criteria)
doing its job: this build asks for local-network access before sync can work at
all, the App Store is the update channel, and there is no direct-download route
onto an iPhone to point at.

### iOS name

```text
Beatrax: Personal Finance
```

### iOS subtitle

```text
Your money, on your devices
```

### iOS promotional text

```text
Beatrax reads the statements your bank already exports and keeps the result in a file on your iPhone. No Beatrax account, no Beatrax service, nothing to sign in to.
```

### iOS keywords

```text
budget,ledger,statement,camt,mt940,iban,offline,expenses,reconcile,spending,envelope
```

### iOS description

```text
Beatrax keeps your money history in a file on your iPhone. There is no Beatrax account and nothing to sign in to.

WHAT IT READS

It reads the statements your bank already gives you, not your banking password: CAMT.053 and MT940, CSV with profiles for N26, Revolut, ING (NL) and ASN, PayPal exports, ICS card PDFs and cash you type in. You say which format a file is; every import is previewed, and re-importing one changes nothing.

It categorises with rules you write, names the counterparty behind an IBAN once for every transaction sharing it, and links a card purchase to the settlement that paid it. Budgets, goals, pots, splits, reconciliation, recurring and unusual-charge alerts, forecasts, a calendar, reports and search too, in 26 languages.

YOUR DEVICES, NOT A SERVER

Sync is optional and off until you pair two devices. On iPhone you pair by scanning the code your other device shows; it carries that device's address, so nothing is searched for. Both then show the same six words to compare before anything moves. Paired devices exchange changes directly over your own network, in a session both ends authenticate and encrypt.

Five things worth knowing:

• Allow Beatrax to find devices on local networks when iOS asks. Until you do, iOS drops every connection to your other device and nothing syncs.
• iPhone cannot search your network for other devices: that needs a capability Apple grants per developer account, which this app lacks. Scanning the code works without it.
• Sync is started, not scheduled. Both devices must be open on the same network at once, and a phone has no background sync: the app lock holds the key that signs your changes, and a background task cannot reach it.
• A relay — one your devices set up, or one you nominate — lets two finish pairing and exchange keys. It does not carry your transactions, and it sees metadata: sizes, timing, and which device identifiers exchange traffic. Traffic analysis is not defended against.
• A paired device is trusted. Removing one mints a new key from then on and re-wraps it to the devices you kept; it does not un-see what was already synced.

Turning sync on cannot be undone: it needs an app lock, encrypts your data from then on, and neither can be switched off.

ENCRYPTION, PRECISELY

At-rest encryption is off until you turn it on, mandatory once you sync. It covers notes, transaction descriptions, and the names and IBANs of who you pay. It does not cover amounts, dates, or your own account name and IBAN. The search index keeps a readable copy of who you pay, your descriptions and notes, so search keeps working, and some merchant names stay in plain text elsewhere in the database file. It raises the cost of casual access to a copied file; it is no defence against somebody who has the file and time.

The key is released by your app lock: a PIN, optionally with Face ID or Touch ID. Lose that passphrase with no backup and no other paired device and your data cannot be recovered.

WHAT IT SENDS

With no optional feature on, this app makes no network connection at all. It has no update check of its own; the App Store installs updates.

Two features reach outside your iPhone, both off until you switch them on, each talking to a service you already have credentials for: open banking for ASN and SNS accounts via Enable Banking with your own API key — it reads accounts, balances and transactions and cannot ask to move money — and exchange rates from the European Central Bank or Frankfurter, with a bundled set used until then.

No analytics, telemetry, crash reporting or advertising. The camera scans the pairing code and nothing else. Connecting a mailbox for receipts is done on the desktop; what it finds reaches the phone by sync.

Beatrax also runs on macOS, Windows, Linux and Android, as a direct download with checksums and a signed manifest each release. No subscription, no in-app purchases. Source published under the Hippocratic License 3.0: source-available, not OSI open source.
```

## Mac App Store

Primary category **Finance**, secondary **Productivity**. Same App Store Connect
fields and the same limits as the iOS listing.

This listing carries two disclosures no other one does, and both are
[`F8-R26`](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md#acceptance-criteria)
rather than good manners. A store build runs in the App Sandbox, so its
auto-import folder lives inside the container several levels down in
`~/Library/Containers`; and a sandboxed build cannot read the real home
directory, so **a ledger written by the direct-download build does not follow a
reader into this one**. Neither is a defect and both are invisible until they
bite, which is exactly why they belong in the copy.

### Mac name

```text
Beatrax: Personal Finance
```

### Mac subtitle

```text
Your money, on your Mac
```

### Mac promotional text

```text
Beatrax reads the statements your bank already exports and keeps the result in a file on your Mac. No Beatrax account, no Beatrax service, nothing to sign in to.
```

### Mac keywords

```text
budget,ledger,statement,camt,mt940,iban,offline,expenses,reconcile,spending,envelope
```

### Mac description

```text
Beatrax keeps your money history in a file on your Mac. There is no Beatrax account, nothing to sign in to.

WHAT IT READS

It reads the statements your bank already gives you, not your banking password: CAMT.053 and MT940, CSV with profiles for N26, Revolut, ING (NL) and ASN, PayPal exports, ICS card PDFs, receipts from a Gmail or Microsoft 365 mailbox you connect, a folder it watches, and cash you type in. You say which format a file is; every import is previewed, and re-importing one changes nothing.

It categorises with rules you write, names the counterparty behind an IBAN once for every transaction sharing it, and links a card purchase to the settlement that paid it. Budgets, goals, pots, splits, reconciliation, recurring and unusual-charge alerts, forecasts, a calendar, reports, search, multi-currency and encrypted backups too, in 26 languages.

WHAT THE APP STORE VERSION DOES DIFFERENTLY

It runs in Apple's App Sandbox, so the folder it watches lives inside its own container, several levels down in ~/Library/Containers — Beatrax shows you where. It does not update itself; the App Store installs new versions. And a ledger made by the direct-download version does not carry over into this one — they keep separate data, so move across with an encrypted backup.

YOUR DEVICES, NOT A SERVER

Sync is optional and off until you pair two devices. You pair by scanning the code the other shows, or typing it in; both then show the same six words to compare before anything moves. Paired devices exchange changes directly over your own network, in a session both ends authenticate and encrypt.

Four things worth knowing:

• Sync is started, not scheduled. Both devices must be open on the same network at once.
• A relay — one your devices set up, or one you nominate — lets two finish pairing and exchange keys. It does not carry your transactions, and it sees metadata: sizes, timing, and which device identifiers exchange traffic. Traffic analysis is not defended against.
• A paired device is trusted. Removing one mints a new key from then on and re-wraps it to the devices you kept; it does not un-see what was already synced.
• Turning sync on cannot be undone: it needs an app lock, encrypts your data from then on, and neither can be switched off.

ENCRYPTION, PRECISELY

At-rest encryption is off until you turn it on, mandatory once you sync. It covers notes, transaction descriptions, and the names and IBANs of who you pay. It does not cover amounts, dates, or your own account name and IBAN. The search index keeps a readable copy of who you pay, your descriptions and notes, so search keeps working, and some merchant names stay in plain text elsewhere in the database file. It raises the cost of casual access to a copied file; it is no defence against somebody who has the file and time.

The key is released by your app lock: a PIN, optionally with Touch ID. Lose that passphrase with no backup and no other paired device and your data cannot be recovered.

WHAT IT SENDS

With no optional feature on, this app makes no network connection at all.

Three features reach outside your Mac, all off until you switch them on, each talking to a service you already have credentials for: a Gmail or Microsoft 365 mailbox, read-only, through Google's and Microsoft's own APIs and an app registration you make yourself, read on your Mac with nothing uploaded; open banking for ASN and SNS accounts via Enable Banking with your own API key, which reads accounts, balances and transactions but cannot ask to move money; and exchange rates from the European Central Bank or Frankfurter, with a bundled set until then.

No analytics, telemetry, crash reporting or advertising.

Beatrax also runs on Windows, Linux, Android and iPhone; the Windows, Linux and Android builds are a direct download with checksums and a signed manifest each release. No subscription, no in-app purchases. Source published under the Hippocratic License 3.0: source-available, not OSI open source.
```

## Microsoft Store

The chosen product type is an **EXE/MSI listing**: the existing signed NSIS
installer, hosted by us and submitted as a versioned immutable URL, which the
Store neither repackages nor re-signs
([the store-submission runbook](store-submission.md) has the reasoning). Two
consequences reach the copy. Self-update is **kept** on this channel, so this is
the only store listing whose honest answer is "one call goes out on its own".
And a Windows desktop does not advertise itself over mDNS — there is no
`dns-sd` or `avahi-publish-service` to do it — so this listing describes pairing
by code and never mentions devices finding each other.

Category **Personal finance**.

### Windows product name

```text
Beatrax
```

### Windows short description

```text
Beatrax reads the statements your bank already exports — CAMT.053, MT940, CSV, PayPal, ICS card PDFs — and turns them into one picture of your month: categorised, reconciled, budgeted and forecast. It keeps everything in a file on your own PC, with no account to create.
```

### Windows description

```text
Beatrax keeps your money history in a file on your own PC. There is no Beatrax account, no Beatrax service and nothing to sign in to. You create an account the first time you open it; that account exists on this PC only.

WHAT IT READS

Beatrax reads the statements your bank already gives you, rather than asking for your banking password:

• CAMT.053 (ISO 20022) and MT940 files
• CSV, with ready-made profiles for N26, Revolut, ING (Netherlands) and ASN
• PayPal activity exports
• ICS credit-card statement PDFs
• Receipts from a Gmail or Microsoft 365 mailbox you connect yourself, matched to the bank rows they belong to
• A watched folder, so a statement you save there is picked up
• Cash and off-bank entries you type in yourself

You tell Beatrax which format a file is; it never guesses, because guessing between CSV dialects that differ only in column order produces confident, wrong answers. Every import is previewed before anything is written, and re-importing the same file changes nothing.

WHAT IT DOES WITH IT

Categorisation with rules you write yourself. Counterparty resolution, so an IBAN you have never seen is named once and every transaction sharing it is labelled. Funding chains, so a card purchase is linked to the bulk settlement that finally paid for it. Self-transfer pairing, so moving money between your own accounts is not counted as spending. Splits and reconciliation. Envelope budgeting, savings goals and pots. Recurring-charge detection with price-drift alerts. Unusual-charge alerts measured against your own history. A 30- to 365-day forecast with what-if scenarios. A bills calendar with a running projected balance. A report builder with saved reports. Full-text search across everything you have kept. Multi-currency, keeping both the original and the settled amount. Tax-deductible tagging with a per-year CSV or PDF export.

The interface is available in 26 languages.

YOUR DEVICES, NOT A SERVER

Sync is optional and off until you pair two devices. You pair by scanning the code the other device shows, or by typing it in, and both devices then display the same six words for you to compare before anything moves. Paired devices exchange changes directly with each other, over your own network, in a session each end authenticates and encrypts. No Beatrax service takes part.

Four things about sync are better read here than discovered later.

• Sync is started, not scheduled. Both devices have to be open on the same network at the same time.
• A relay — one your own devices set up on your network, or one you nominate yourself — exists so two devices can finish pairing and exchange keys. It does not carry your transactions. Those only ever cross the direct connection between the two devices.
• A relay sees metadata: sizes, timing, and which device identifiers exchange traffic. Traffic analysis is not defended against.
• A paired device is trusted. Removing one mints a new key for everything from that point on and re-wraps it to the devices you kept. It does not un-see what was already synced.

Turning sync on cannot be undone. It requires an app lock, it encrypts your data from then on, and neither sync nor the app lock can be switched off again.

ENCRYPTION, SAID PRECISELY

There is an app lock — a PIN you choose, optionally with Windows Hello. There is also at-rest encryption, which is off until you turn it on and becomes mandatory once you sync.

At-rest encryption covers notes, transaction descriptions, and the names and IBANs of who you pay. It does not cover amounts, dates, or your own account name and IBAN. The search index keeps its own readable copy of who you pay, your transaction descriptions and the notes you write, so that search keeps working, and some merchant names remain in plain text elsewhere in the database file. It raises the cost of casual access to a copied file. It is not a defence against somebody who has the file and the time to read it.

If you lose your app-lock passphrase and have no backup and no other paired device, your data cannot be recovered. That is the same property that means nobody else can be handed it either.

WHAT IT SENDS

One call goes out on its own: a check for a new version, which you can turn off in settings. At that point, with every optional feature off, Beatrax makes no network connection at all.

Three features reach outside your PC, all off until you switch them on, and each talking to a service you already hold the credentials for:

• A Gmail or Microsoft 365 mailbox, read-only, through Google's and Microsoft's own APIs and an application registration you make yourself. Messages are downloaded and read on your PC. Nothing is uploaded.
• An open-banking connector for ASN and SNS accounts, through Enable Banking, with an API key you register yourself. It can read accounts, balances and transactions. Payment initiation is not merely switched off: the connector has no field that could ask for it.
• Exchange rates, from the European Central Bank or Frankfurter. Until you turn that on, a set of rates bundled with the app is used, so currency conversion works with no network at all.

There is no analytics, no telemetry, no crash reporting and no advertising.

NOT ONLY FROM A STORE

Beatrax is also published as a direct download for Windows, macOS, Linux and Android, with SHA-256 checksums and a signed manifest for every release. This listing is an addition to that, never a replacement for it.

FREE, AND YOU CAN READ THE CODE

No subscription, no in-app purchases, no advertising. The source is published under the Hippocratic License 3.0 — source-available, with ethical-use clauses, which is not the same as OSI-approved open source.
```

### Windows product features

Twelve of the twenty allowed. The Store renders them as a bulleted list, so
they carry no bullet of their own.

```text
Reads CAMT.053, MT940, bank CSV, PayPal exports and ICS credit-card PDFs
Previews every import before anything is written, and never duplicates a file you import twice
Names the counterparty behind an IBAN once, for every transaction that shares it
Links a card purchase to the bulk settlement that finally paid for it
Envelope budgeting, savings goals and pots over one real balance
Finds recurring charges and tells you when a price has moved
Flags charges that are unusual against your own history
Forecasts your balance 30 to 365 days out, with what-if scenarios
Full-text search across everything you have kept
Multi-currency, keeping both the original and the settled amount
Tax-deductible tagging with a per-year CSV or PDF export
Optional sync straight between your own devices, with no Beatrax service in the middle
```

### Windows additional system requirements

These are requirements in the Store's sense — things a reader needs that the PC
may not supply — rather than hardware.

```text
Sync needs a second device of your own, and both open on the same network at the same time
Email receipt scanning needs a Gmail or Microsoft 365 mailbox and an app registration you make yourself
The open-banking connector needs an ASN or SNS account and your own Enable Banking API key
Statement files you already have: Beatrax reads exports, it does not sign in to your bank
```

## Direct download

Not a store listing, and the reason it is here: every listing above is additive
to this channel and none of them retires it
([F8-R1](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md#acceptance-criteria)).
Copy for the download page has to stay true in both directions — which builds
are signed, and which are not.

### Download page one-liner

```text
Beatrax runs on macOS, Windows, Linux and Android, straight from the release page. Every release publishes SHA-256 checksums and an Ed25519-signed manifest, so you can verify exactly what you downloaded.
```

### Download page detail

```text
macOS — a signed and notarised disk image, for Apple Silicon. An Intel Mac has to build from source.

Windows — a signed installer. It updates itself, and you can turn that off.

Linux — a portable image and a native package. Neither is signed; verify the checksum.

Android — a signed APK, carrying the same signing key as the Google Play build, so an installation from one can be upgraded by the other.

iPhone and iPad — the App Store is the only route. There is no side-loadable build.

Every release publishes a SHA-256 checksum file covering every other asset, an Ed25519 signature beside it, and a signed update manifest per platform. The verification steps are in the repository.
```

## Claim to evidence

Every factual sentence in the copy above, and what makes it true. A row that
could not be filled is not in the copy — it is in
[Claims that were cut](#claims-that-were-cut) instead.

### What the product is

| Claim | Evidence |
|---|---|
| Keeps your money history in a file on your own device | `UserDataPathService` resolves the per-platform user-data directory; [F7](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f7-data-locations.md) |
| No Beatrax account, nothing to sign in to | [F8](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md), "Accounts exist; they are local"; the reviewer note in [store-submission.md](store-submission.md) |
| In 26 languages | 26 cases in `Modules/Core/Public/Enums/Locale.php`, and 26 locale directories under `Modules/*/Resources/lang/` — counted, not carried over |
| Requires Android 13 or later | `min_sdk` is 33 in `mobile-app/config/nativephp.php`; [F8-R17](https://github.com/beatrax-app/spec/blob/main/10-functional/features/f-platform/f8-app-store-distribution.md#acceptance-criteria) pins the level in this product |
| No subscription, no in-app purchases, no advertising | No billing, purchase, licence-key or entitlement path exists in any of the 35 modules, and there is no account system to attach one to |
| Hippocratic License 3.0, source-available, not OSI open source | `LICENSE`; [license-rationale](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md); [brand-rules](https://github.com/beatrax-app/spec/blob/main/60-brand/surface-mapping.md) states the distinction as a requirement |

### What it reads and does

| Claim | Evidence |
|---|---|
| CAMT.053, MT940, PayPal exports, ICS card PDFs | `SourceFormat` carries exactly `Camt053`, `Mt940`, `IcsPdf`, `PaypalCsv`, `Eml` and `Mbox` |
| CSV profiles for N26, Revolut, ING (NL) and ASN | `CsvPresetRegistry` declares exactly those four preset ids |
| Cash you type in | `Modules/CashBook`; [A7](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a7-cash-book.md) |
| You say which format a file is | [A1](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a1-source-formats.md) — declared, never sniffed, with a header check against the declaration |
| Every import is previewed | [A2](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a2-import-wizard.md) — nothing is written before you confirm |
| Re-importing one changes nothing | [A3](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a3-idempotency.md) — row fingerprinting |
| Categorises with rules you write | `Modules/Categorization`; B2, B3 |
| Names the counterparty behind an IBAN | `Modules/Counterparties`; B4 |
| Links a card purchase to the settlement | `Modules/Chains`; B5 |
| Splits, reconciliation | `TransactionSplit` and `ReconcilePage` in `Modules/Ledger`; B7, B8 |
| Budgets, goals, pots | `Modules/Budgets`, `Modules/Goals`, `Modules/Pots`; D1, D2, D3 |
| Recurring charges and their price changes | `Modules/Recurring`, `Modules/DriftAlerts`; C2, C3 |
| Unusual-charge alerts | `Modules/Anomaly`; C4 |
| Forecasts, bills calendar, reports | `Modules/Forecasting`, `Modules/Calendar`, `Modules/Reports`; C5, C6, C7 |
| Search across everything you have kept | `Modules/Search`; B9 |
| Multi-currency, both amounts kept | `Modules/FX`; B10 |
| Tax-deductible tagging with a per-year export | `Modules/Tax`; D4 |
| Encrypted backups (Mac and Windows copy) | `Modules/Core/Internal/Backup/`; F4 |

### Sync

| Claim | Evidence |
|---|---|
| Pair by scanning the code, or typing it in | `QrPayloadBuilder` and `WordCodeEncoder`; E2 |
| Both then show the same six words to compare | `SafetyNumberDeriver` hashes both Ed25519 public keys and maps the digest onto six words from `Bip39WordList`; E2-R7, E2-R8 |
| Exchange changes directly over your own network, each end authenticating and encrypting | `SyncWebSocketHandler` and `LanSyncClient`, with a Noise-pattern handshake inside the LAN WebSocket; E3 |
| No Beatrax service takes part | Every outbound call in `Modules/Sync` addresses either a peer's LAN address or the relay endpoint the reader configured; `APrivateKeyNeverLeavesTheDeviceThatMintedItArchTest` holds the private halves on the device that minted them |
| Sync is started, not scheduled | Nothing in the tree schedules a sync; the only interval inside the listener is the pairing courier |
| A phone has no background sync | `MobileBackgroundSchedule` declares `mobile.sync-pull` unrunnable — measured on a paired, fully synced handset as six firings and no syncs; `ThePhoneSchedulesNoBackgroundSyncTest`; [background-sync-cannot-hold-the-key](../features/mobile/background-sync-cannot-hold-the-key.md) |
| A relay does not carry your transactions | `TheRelayHelpPromisesNoPullThePhoneCannotMakeTest`; the shipped string `sync::devices.relay_endpoint_help` says the same |
| A relay sees sizes, timing and which device identifiers exchange traffic | G1-R15; the relay mailbox row carries a sender id, a recipient id, the blob and three timestamps and nothing else, asserted column-by-column in `RelayZeroKnowledgeTest` |
| A paired device is trusted; revocation is not retroactive | G1-R16, E2-R17, [ADR-0015](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0015-multi-master-p2p-sync.md); `RemovingADeviceMustNotDeleteWhatItWroteTest` |
| Removing one mints a new key and re-wraps it to the devices you kept | `GdkRotationService`; `RevokedDeviceCannotDecryptFutureEpochTest`; [device-removal-and-epoch-rotation](../features/sync/device-removal-and-epoch-rotation.md) |
| Turning sync on cannot be undone | `SyncEnableForcesEncryptionTest`; `AppLockProvisioner` refuses to disable the lock while encryption is on; the shipped string `sync::devices.enable_sync_help` |
| iPhone cannot search the network for other devices | No `com.beatrax.mobile` provisioning profile carries `com.apple.developer.networking.multicast`, and the config key is env-gated off; [ios-lan-discovery-entitlement](../features/mobile/ios-lan-discovery-entitlement.md) |
| iOS drops every connection until local-network access is allowed | Measured on an iPhone: the flow to the desktop was allocated and closed having carried zero bytes while the system prompt was still queued; same page |

### Encryption

| Claim | Evidence |
|---|---|
| Off until you turn it on, mandatory once you sync | The settings card offers a decline on a single device and the sync path activates with none; `DevicesAndSyncEncryptionUiTest` |
| Covers notes, transaction descriptions, and the names and IBANs of who you pay | `SensitiveFieldRegistry` seals exactly fourteen columns, `OpLogFieldCrypto` does it with XChaCha20-Poly1305 |
| Does not cover amounts, dates, or your own account name and IBAN | The same registry's written plaintext list; G1-R14; [ADR-0018](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0018-amounts-plaintext-at-rest.md) |
| The search index keeps a readable copy of who you pay, your descriptions and notes | `SearchedColumns`; B9-R14; [sensitive-columns-at-rest](../features/sync/sensitive-columns-at-rest.md) |
| Some merchant names stay in plain text elsewhere | The same page names the merchant-name column as the highest-value column still readable |
| Raises the cost of casual access; no defence against somebody who has the file and time | ADR-0018's own words, which it requires the product's copy to repeat |
| The key is released by your app lock | A random data key is wrapped under an Argon2id derivation of the PIN and separately of the account password — `AppLockKdf`, `AppLockProvisioner`; E4-R3, E4-R4 |
| Lose that passphrase and it cannot be recovered | The shipped string `sync::devices.no_recovery_warning` |

### What it sends

| Claim | Evidence |
|---|---|
| With no optional feature on, no network connection at all (Play, App Store, Mac App Store) | G1-R4; and those builds carry no update feed to compose a URL from — `AStoreBuildCarriesNoFeedItCouldInstallFromTest` for mobile, `TheStoreLaneIsBuiltAndReadArchTest` for the Mac lane's `NATIVEPHP_UPDATER_ENABLED=false` |
| One call goes out on its own, and you can turn it off (Microsoft Store, direct download) | `HttpPublisherManifestFetcher` fetches a manifest from the publisher URL; `UpdateCheckSettingsSection` is the control; `TheUpdateCheckHasAnOffSwitchTest` asserts nothing is sent once it is off; G1-R3 |
| Open banking for ASN and SNS accounts through Enable Banking with your own key | `Modules/OpenBanking`; `EnableBankingHttpClient` checks every URL against a one-entry host allow-list before a token is attached; [ADR-0020](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0020-open-banking-byo-key-ais-only.md) |
| It cannot ask to move money | `EnableBankingAccessScope` carries three booleans and no payments member, so no value could request one; G1-R8, A6-R7 |
| Exchange rates from the European Central Bank or Frankfurter, with a bundled set until then | `EcbRateProvider` is tried first and `FrankfurterRateProvider` second; the bundled snapshot reaches no network and the online setting defaults off |
| A mailbox, read-only, through the providers' own APIs and your own app registration | `GmailApiClient` and `GraphApiClient` with read scopes; the wizard takes the reader's own client id and secret; the IMAP packages sit in composer's `conflict` block, so the resolver refuses to install one |
| Messages are read on your machine, nothing is uploaded | The `.eml` blob store writes under the user-data path at owner-only permissions; there is no upload path; [email-scan architecture](../features/email-scan/architecture.md) |
| No analytics, telemetry, crash reporting or advertising | G1-R5, G1-R6, F8-R22; `NoShippedMobileDependencyCarriesAnalyticsTest` scans the mobile lock file and every plugin manifest's Gradle and CocoaPods coordinates, with a planted-offender control so a scanner that matches nothing fails |
| Android's backup system does not copy Beatrax's data off your phone (Play) | The Android manifest's `allowBackup` is rewritten to false with backup and data-extraction rules beside it; `ExcludeDataFromBackupPatchTest` |
| The camera scans the pairing code and nothing else (iOS) | The camera has one consumer, the pairing scan view, and the shipped purpose string says exactly this |
| Connecting a mailbox is done on the desktop | The inboxes page reads the platform once and says so; the five email-scan tasks are desktop-only in `MobileBackgroundSchedule` |

### Channel facts

| Claim | Evidence |
|---|---|
| Mac App Store: the watched folder lives inside the sandbox container | The drop folder is an app-owned directory under the storage root, which the sandbox redirects into `~/Library/Containers`; [store-submission.md](store-submission.md) records it as listing copy under `F8-R26` |
| Mac App Store: it does not update itself | Electron disables the updater in a `mas` build and the lane writes `NATIVEPHP_UPDATER_ENABLED=false`; F8-R20, F8-R21 |
| Mac App Store: a direct-download ledger does not carry over | `HOME` is redirected into the container and the real home is unreadable — measured, both sandboxed and with an unsandboxed control |
| Also a direct download, with checksums and a signed manifest | The publish job writes a SHA-256 file covering every artefact, signs it and each manifest with Ed25519, and a later job re-downloads and re-verifies them; [release-cut.md](release-cut.md); F8-R1 |
| Windows: it updates itself, and you can turn that off | The EXE/MSI listing keeps the existing installer and its self-update; `UpdateCheckSettingsSection` is the off switch |
| Android APK carries the same signing key as the Play build | F8-R4, and [mobile-release.md](mobile-release.md) — the release key must never be replaced, and the built APK's signer fingerprint is verified against the recorded one |
| macOS direct download is Apple Silicon; Linux is unsigned | [platform-matrix](https://github.com/beatrax-app/spec/blob/main/20-architecture/platform-matrix.md) records both |
| iPhone has no side-loadable build | Distribution goes through the App Store profile; there is no direct-download shape for iOS |

## Claims that were cut

Twenty-three claim shapes were written, could not be traced, and were **removed
rather than softened**. Several are the most attractive sentences a listing
could carry, which is the point: this list is the honest distance between what
the product does and what would sell.

| Claim | Why it is not in the copy |
|---|---|
| "Your data never leaves your device" · "no cloud" | Four optional features send data to a third party by design, and on the direct-download and Microsoft Store builds the update check fires on a default install. The catalogue is the enumeration a listing may not undercut (G1-R1, F8-R26), so the copy names the calls instead |
| "Encrypted" unqualified · "end-to-end encrypted ledger" · "zero-knowledge" · "military-grade" · "AES-256" · "encrypted database" | G1-R14 and ADR-0018 forbid the first three shapes outright; there is no SQLCipher and the cipher is XChaCha20-Poly1305, not AES. `Modules/Mobile/module.json` calls it "on-device encrypted SQLite" — internal shorthand for column sealing, and not a phrase to lift into a listing |
| "Your devices stay in sync automatically" · "background sync" | Nothing in the tree schedules a sync, and the phone's background pull was withdrawn after measurement — six firings, no syncs |
| "Changes wait in the relay until your other device wakes up" | The most attractive sentence in the set, and false. A relay carries pairing frames and key wraps; transactions only ever cross the direct connection |
| "The relay only ever holds ciphertext it cannot read" | Key wraps are sealed to the recipient. Pairing frames are signed plaintext, so a relay can read device identifiers, public keys and a device's display name |
| "Your devices find each other automatically" | Cut from **every** listing, not only iOS. An iPhone cannot browse at all, and a Windows desktop does not advertise itself because neither `dns-sd` nor `avahi-publish-service` exists there. What is left — Mac and Linux finding each other — is too narrow to be a listing sentence |
| "Remove a device and it loses access to your data" | Revocation is forward-only (G1-R16), and the blind-index key is never rotated, so a removed device keeps the ability to test whether a given merchant appears in a database file it later obtains |
| "Your rules follow you to your other devices" | Categorisation rules are deliberately device-local; no rule mutation is emitted into the op log, and the shipped copy tells the reader so |
| "Snap a photo of your receipt" · OCR · attachments on a transaction | None of the three exists. "Receipts" here means email receipts. The camera has exactly one consumer, the pairing scanner, and the shipped purpose string promises nothing is photographed or stored |
| "Connect your bank" | The open-banking connector reaches ASN and SNS accounts and no others |
| "Your full history arrives on a newly paired device" | Catch-up deliberately withholds operations whose author the receiver cannot yet verify, and reports how many it withheld |
| "Open source" | Hippocratic 3.0 is source-available; the ethical-use clauses are exactly what disqualifies it from the Open Source Definition, and the brand rules treat the distinction as load-bearing |
| Screenshot, screen-recording or app-switcher protection | `FLAG_SECURE` is set nowhere in the tree |
| iOS Data Protection · the iOS backup exclusion | There is no `NSFileProtection` use at all. The backup exclusion is real code and was verified on a locally built artefact, but no pipeline builds the iOS artefact and which file it covers is an open question (E4-R25). Two research passes disagreed about it, which is its own reason to leave it out |
| A minimum macOS or Windows version | Nothing in the tree pins one, so any number would be invented. The Android line survives only because `min_sdk` is pinned in config |
| WCAG 2.2 Level AA conformance | It is the stated target; no automated audit runs in the pipeline, and the specification records it that way on purpose |
| "Shared household finances" | Owner and partner accounts ship, but the roadmap holds a genuinely shared household surface out of v2.0 as a milestone of its own. "A second account for your partner" is the true version, and it did not earn the space |
| "Device-verified on Android" | Two-device pairing acceptance was taken on an iPhone. Android acceptance is not recorded, and neither is a measured desktop-to-desktop sync on Windows or Linux |
| "Standard Noise Protocol" · "TLS" or "HTTPS" on the LAN leg | The handshake is vector-pinned against its own fixture and does not reproduce the published Noise vectors, so it is a Noise-pattern handshake rather than a wire-interoperable one. The LAN leg is plain `ws://` with that handshake inside, deliberately |
| "Exchange rates from Frankfurter" | The ECB provider is tried first; Frankfurter is the fallback. Name both or neither |
| "Every external link is checked against an allow-list" | Only links the shell opens are. Four in-app anchors — the Google, Entra and Enable Banking consoles, and the privacy policy — go straight out, deliberately |
| Anything in the future tense | A listing is not a roadmap. And nothing warns before a signing identity lapses: on a store channel that turns an expiry into an outage for people who have no other route to an update, so no cadence is promised either |
| "Available on the App Store" or "on Google Play", anywhere | No listing is live. The download page's store slots stay empty until one is |

Two of these are worth restating because they are shipped copy elsewhere that
contradicts the drafts above, not merely temptations avoided:
**the relay does not carry transactions**, and **a paired device that has been
removed is not cut off from what it already holds**.

## What needs a privacy policy

Both mobile stores require a privacy policy URL, and the application already
links one: `LegalLinks::PRIVACY_POLICY_URL` is `https://beatrax.app/privacy`,
rendered on the settings page. A URL existing is not the same as the policy the
submission needs, and there are two gaps.

**The document is a privacy statement, not a policy with a consent basis.**
[The store-submission runbook](store-submission.md) still carries "a privacy
policy covering the LAN sync and the financial data, with express consent" as
outstanding work sized **M**. Nothing has closed it.

**It describes a smaller outbound surface than the catalogue.** The page's own
table names three calls — mail, open banking, exchange rates. The catalogue has
seven. A privacy page a listing points at *is part of the listing*, and
describing a smaller outbound surface than the catalogue is precisely what
`F8-R26` prohibits. The missing four are the update check, sync peers, the sync
relay and external-link opening.

So before any submission the page must, at minimum, enumerate all seven calls,
describe LAN sync and what a relay observes, and state the basis on which
financial data is processed. Until it does, a listing pointing at it asserts a
policy that does not say what the store was told it says.

## Before you publish

1. **Re-derive the declarations from the outbound-call catalogue** — `F8-R15`
   requires it before any submission that follows a change to the catalogue, and
   a change there changes this page's copy too.
2. **Confirm the tag actually carries what the copy describes.** Sync, the rules
   engine, splits, reconciliation, budgets, reports and the notification inbox
   are merged but were not in the last tag.
3. **Read [the signing identity register](signing-identities.md).** Nothing
   warns before an identity lapses, and on a store channel a lapse stops updates
   reaching people who have no other route to one.
4. **Keep France out of the release territories** until the ANSSI declaration is
   filed and its code ships in the bundle (`F8-R11`).
5. **Fix the website before a listing points at it.** Three things are stale or
   wrong there: the hero and navigation call the project "open source" where the
   brand rules require "source-available"; the download page still says Beatrax
   is in no store and that which stores are in scope is an open question, which
   ADR-0032 settled; and the privacy page's outbound table names three of seven
   calls.
6. **Re-count every field** against the store's currently published limit before
   pasting. The counts on this page were taken from the blocks above; the limits
   were read on the day this page was written.
7. **Screenshots come from a version that exists** (`DES-R12`), and refreshing
   them is on the release checklist.
8. **The Apple keyword list deliberately carries no third-party trademark.**
   "ynab" and "paypal" would both be relevant search terms, and both are
   somebody else's mark. Adding them is a decision to take knowingly rather than
   an oversight to correct.

## Related

- [What a store submission has to declare](store-submission.md) — the
  declarations, the review notes, and the rule this page is bound by
- [Mobile release](mobile-release.md) — building and signing the artefacts these
  listings carry
- [Cutting a release](release-cut.md) — the direct-download channel no listing
  retires
- [Signing identities](signing-identities.md) — what expires, and when
- [Sensitive columns at rest](../features/sync/sensitive-columns-at-rest.md) —
  the column list the encryption sentence is derived from
- [iOS and the multicast entitlement](../features/mobile/ios-lan-discovery-entitlement.md)
- [A background task on the phone cannot hold the key](../features/mobile/background-sync-cannot-hold-the-key.md)
