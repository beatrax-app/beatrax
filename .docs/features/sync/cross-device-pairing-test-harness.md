# Cross-device pairing test harness

Pairing is a ceremony between two devices that each own a **separate
database**. Almost every pairing test in this suite fakes that with one
database: a single `pairing_tokens` row carrying two distinct `device_id`
values, one per "side". That shortcut is convenient and it is also blind.
`confirm()` run against a shared row proves only that the state machine
transitions — it can never prove that anything crossed the relay, because
there was never a second store for it to cross into. A regression where
cross-device propagation silently did not happen looked green under that
convention.

`Modules\Sync\Tests\Support\CrossDevicePairingHarness` is the trait that
removes the shortcut.

## The three connections

The trait configures three physically distinct in-memory SQLite connections
and purges any previous binding for each:

| Connection | What it is |
|---|---|
| `desktop` | The desktop device's own local database |
| `phone` | The phone device's own local database |
| `relay` | The zero-knowledge mailbox store, reachable by both and holding neither device's `pairing_tokens` |

`desktop` and `phone` get the production `pairing_tokens`, `device_registry`
and `sync_encryption_state` schema, including the indexes, so uniqueness and
lookup behaviour match production rather than a simplified test table.

## Making a device "act as itself"

Every collaborator in this codebase resolves its storage through
`$this->db->connection()` — the manager's **default** connection, with no
connection name threaded through service signatures. `asDevice()` exploits
exactly that: it swaps the DatabaseManager's default connection for the
duration of a closure and restores the previous default in a `finally`, so a
throwing assertion cannot leak the wrong default into the next block.

That is what makes "the phone does X, then the desktop does Y" faithful.
Code under test is entirely unaware there is a harness.

## The relay is real, the network is not

The trait does **not** re-implement the relay as a test double. It builds an
`Illuminate\Http\Client\Factory` whose `fake()` handler converts each
outbound `RelayClient` request into an Amp request and invokes the real
`RelayServeCommand::route()` through reflection, then converts the Amp
response back. The consequence is that the actual wire contract and the
per-device drain authorisation run for real in every test, instead of being
restated (and therefore able to drift) in test code.

Two details make that work:

- **The handler runs on the `relay` connection.** The fake handler swaps the
  default connection to `relay` around the invocation, so `RelayMailbox`'s
  own `$this->db->connection()` calls land on the dedicated relay store
  rather than on whichever device connection happened to be active when
  `RelayClient::deliver()`, `drain()` or `confirm()` was called.
- **A stable fake source address.** Delivery reads the remote address for its
  rate-limit bucket. An in-process harness binds no socket, so the mocked Amp
  client returns a fixed `127.0.0.1` address; without it, rate limiting has
  no bucket key to work with.

The `RelayClient` built this way is bound into the container as a
**singleton instance**, so anything that resolves `RelayClient` out of the
container later — Livewire components, `PairingFrameCourier` — gets the
fake-transport client rather than opening a real connection. That courier
tries the LAN first and only falls to the relay when the peer is
unreachable, holding the frame in `PairingPeerOutbox` when neither road is
open, so the fake client stands in for the middle leg of three rather than
for the whole delivery. Because the harness never binds a socket it also
needs no TLS material of its own.

## `relay_mailbox` means two different things

This is the detail that costs an afternoon if you miss it. The table name
`relay_mailbox` appears on **both** kinds of connection, with two unrelated
roles:

- On `desktop` and `phone` it is that device's **local outbox**.
  `GdkRotationService` writes epoch-wrap entries into the local
  `relay_mailbox` — the same rows serve a live LAN push and an eventual
  relay forward.
- On `relay` it is the **transport-level zero-knowledge mailbox** that the
  real `RelayServeCommand` handler reads and writes for pairing frames.

So the harness migrates the relay schema onto all three connections, not
just `relay`. Skip the two device copies and the GDK epoch fan-out tests
fail on a missing table, with an error that points at the transport rather
than at the local outbox that is actually absent.

## Teardown is not optional

The relay configuration this trait writes is partly **on disk**, not in the
in-memory databases: `sync-relay-token.json`,
`sync-relay-drain-secret.json` and `sync-relay-drain-registry.json` under the
secrets path, plus `sync/relay.json` under the app path. Call
`crossDevicePairingTearDown()` from the consuming test file's `afterEach()`.
Without it, a later unrelated test in the same process starts with a relay
already configured and a drain registry already populated.

## See also

- [Sync architecture](architecture.md) — the pairing ceremony, the relay
  transport, and the GDK epoch fan-out these tests exercise.
