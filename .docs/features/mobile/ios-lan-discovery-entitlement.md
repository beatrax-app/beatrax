# iOS and the multicast entitlement: why LAN discovery finds nothing

On a real iPhone, `PeerDiscovery::browse()` returns an empty list every time,
while the identical PHP on macOS finds the desktop instantly. Nothing in the
Beatrax code is wrong. iOS is dropping the datagram.

## What discovery actually does

`Modules/Sync/Internal/Transport/Discovery/MulticastMdnsQuery` speaks mDNS
itself rather than shelling out to `dns-sd` or `avahi`, neither of which exists
on a phone. It opens an ordinary UDP socket and calls `stream_socket_sendto()`
with `224.0.0.251:5353`, setting the RFC 6762 §5.4 unicast-response bit so the
answers come straight back to that socket and no group join is needed.

That is a **raw BSD socket sending to a multicast address**, and since iOS 14
Apple gates exactly that on `com.apple.developer.networking.multicast`.

Two declarations are commonly mistaken for covering it, and neither does:

- **`NSLocalNetworkUsageDescription`** gates *unicast* traffic to local
  addresses. The phone's `LanSyncClient` dial to the desktop's `sync:serve`
  listener needs it, which is why it is declared in
  `mobile-app/config/nativephp.php`, and why the permission prompt appears at
  all. It does not permit a multicast send.
- **`NSBonjourServices`** declares which service types the app may browse
  *through NWBrowser / NSNetService*. It exempts Apple's own discovery APIs,
  not a socket the app drives itself.

So the symptom is maximally confusing: the system prompt appears, the reader
grants Local Network access, and discovery still returns nothing. The pairing
screen then asks "are both devices on the same network?" — and their network is
fine.

## Why the declaration kept disappearing

`mobile-app/nativephp/ios/` is generated and git-ignored. Writing the
entitlement into `NativePHP/NativePHP.entitlements` there looks like it works
and never reaches a binary, because `native:build ios` rewrites that file
**from scratch**:

`Native\Mobile\Commands\BuildIosAppCommand::updateEntitlementsFile()` composes
a whole new plist out of three config keys — `deeplink_host`,
`permissions.push_notifications`, `permissions.nfc` — and writes it over
whatever was there. Beatrax sets none of the three (`deeplink_scheme` is
deliberately `null`; there are no push notifications and no NFC), so the file
it produces is a literal empty `<dict/>`. That is byte-for-byte what
`codesign -d --entitlements` reports on the installed app.

A patch applied before the build cannot survive this, and neither can a
hand-edit. The declaration has to arrive *after* the rewrite.

## Where the declaration lives now

`configureXcodeProject()` runs `compileIosPlugins()` after
`updateEntitlementsFile()`, and `IOSPluginCompiler::mergeEntitlements()` merges
entitlements into the file the command just emptied. Upstream it reads plugin
manifests only; `scripts/nativephp_ios_local_network_discovery.php` patches it
to read `config('nativephp.entitlements')` as well — the same shape in which it
already reads `config('nativephp.permissions')` for Info.plist entries.

The declaration therefore lives in `mobile-app/config/nativephp.php`, which is
committed, and the generated tree is never edited.

Two details are load-bearing:

- The patch rewrites **`IOSPluginCompiler`, not `BuildIosAppCommand`**. Artisan
  instantiates every registered command class before it fires
  `CommandStarting`, which is when `NativeBuildPatches` runs. A command file
  rewritten at that point is already loaded, so the patch would only take
  effect on the *next* invocation. The compiler is resolved from the container
  later in the build and picks the patched file up in the same process.
- The patch also relaxes the early return in `compile()`. With no plugin
  shipping iOS data the compiler returns several statements before the merge,
  and app-declared entitlements would go with it.

`scripts/nativephp_ios_local_network_discovery.php` still writes
`NSBonjourServices` into the generated `Info.plist`, which the build does not
regenerate wholesale.

## The entitlement is not ours to grant

`com.apple.developer.networking.multicast` is one Apple issues per Team, on
request, through a form. No provisioning profile on this account carries it —
including the `com.beatrax.mobile AppStore` profile, whose entitlements are
`application-identifier`, `beta-reports-active`,
`com.apple.developer.team-identifier`, `get-task-allow` and
`keychain-access-groups`.

Declaring it before the grant lands does **not** degrade to "no discovery". It
stops the build at signing:

```
Provisioning profile "…" doesn't include the
com.apple.developer.networking.multicast entitlement.
```

So the config key is env-gated and off:

```php
'entitlements' => filter_var(env('IOS_MULTICAST_ENTITLEMENT', false), FILTER_VALIDATE_BOOL)
    ? ['com.apple.developer.networking.multicast' => true]
    : [],
```

**Until Apple grants it, mDNS peer discovery does not work on iOS.** That is a
platform limitation, not a bug to keep hunting. The desktop and Android are
unaffected — Android needs a multicast lock, which is a runtime API rather than
a grant.

A phone can still pair, but only because the QR arm was made to carry the
desktop's address. It did not always: `QrPayloadBuilder` emitted `token`, `ed`,
`kx`, `device`, `name` and the optional relay trio and nothing else, so a
scanned pairing had a token, a name and no idea where the peer was. Delivery
then fell to `LanPairingFrameCourier`, which resolved the peer by browsing —
the one thing an iPhone cannot do. Scanning the code was documented as the
escape from the entitlement and led to the same dead end.

`QrPayloadBuilder::buildUri()` now appends `&host=&port=` from
`PairingLanAdvertisement`, and `LanPairingFrameCourier::deliver()` takes an
out-of-band `$known` peer it tries before any browse. `ScannedPeerAddress`
reads it back off `pairing_tokens.initiator_lan_host/port`, the columns
`seedFromInitiator()` had always written and nothing had ever read on this
road. The address is unicast, which `NSLocalNetworkUsageDescription` already
covers — an IP rather than the SRV record's `.local` name, because resolving a
`.local` name is itself multicast and would need the entitlement all over
again. Trust is unchanged: an address decides which machine is asked, never
which one is trusted, and the safety number remains the only gate.

It is the typed-code arm, which depends on finding the peer, that has nothing
to find.

### The same address, needed again on the way back

Carrying the address fixed the leg that *sends*. The leg that *collects* kept
the browse, and on an iPhone that is the same dead end one step later:
`LanPairingFramePuller::pullAndApply()` asked `LanPeerBrowser` for peers, which
sources them only from `PeerDiscovery::browse()`. So the phone could deliver its
accept and could never fetch the confirm that answers it. Measured on a real
iPhone against a desktop on the same wifi: the phone POSTed its accept 112 times
to the address the QR named, and issued **zero** `GET /pair/frames` in the whole
session. Both ceremonies reached `confirmed` on the desktop and sat on "Waiting
for the other device to confirm…" on the phone until they were cancelled.

Nothing self-heals it. `PendingPairingCourier::reEmitOwnConfirm()` stops once the
row it owns has `peer_confirmed`, which is true the moment the desktop's own
confirm lands — so the desktop sends its confirm once, and the one road back is
the pull.

`pullAndApply()` now takes the same two roads `deliver()` does, scanned address
first and browse second, through `ScannedPeerAddress::forCollector()` — the
in-flight rows on which *this* device is the responder. On the device that had
hung twice, the first poll after the fix issued one
`GET /pair/frames?device=<its own id>` and the ceremony completed on both
screens.

The two legs are one mechanism and are easy to fix by halves: this was the same
defect as the one above, found again on the return path, because only the send
path had been taught the road.

If the grant is refused, the fix is not to keep the entitlement flag: it is a
discovery path that uses `NWBrowser` through a native plugin, which
`NSBonjourServices` already covers, behind the existing `PeerDiscovery`
contract.

## What the pairing screen says when nothing answers

The screen used to answer `PairingOfferLookup::NoPeerReached` with "Cannot
reach the other device. Make sure both are on the same network and sync is
enabled on the desktop." On an iPhone all three claims were false at once —
same wifi, `sync:serve` live, seven interfaces advertising — and the message
appeared while the system's own "allow Beatrax to find devices on local
networks?" prompt was still sitting unanswered on top of the app.

`AcceptsPairingCode::nothingAnsweredKey()` picks between three lines, and none
names a cause this device cannot observe — it knows only that it asked, heard
nothing, and whether the question could leave this device at all:

| `silenceMeansNoPeers()` | camera | Key |
|---|---|---|
| `true` | either | `mobile::pairing.errors.no_peer_answered` |
| `false` | usable | `mobile::pairing.errors.no_peer_answered_ios` |
| `false` | refused | `mobile::pairing.errors.no_peer_answered_camera_off` |

The iOS line says the network search does not work on iPhone *yet* and sends
the reader to the camera, which needs no discovery — true only since the QR
began carrying an address; before that the advice named the other dead end. **That line is bound
to this page.** If the multicast entitlement is granted and the search starts
working, `no_peer_answered_ios` becomes false and has to go — the general line
is true on iOS too from that moment.

The third line exists because sending a reader to the camera is the one piece of
advice that cannot be given to the reader whose camera is the road that was
refused. That pair is not hypothetical: `enterACode()` lands a device with no
usable scanner on the typed-code step and raises the amber notice, whose own
copy — *"Camera access is off. Enter the code from the other device instead."*
— was then printed directly above the line telling them to scan. Two orders on
one screen, each ruling out the other's, and this blade carries no relay
affordance to escape to: the relay arm is reachable only from a scanned QR.
`ChoosesCodeEntryArm::entryArmNotice()` now resolves the amber slot off the same
two signals, so the pair cannot disagree:

| `silenceMeansNoPeers()` | camera | Amber notice |
|---|---|---|
| `true` | usable | none |
| `true` | refused | `mobile::pairing.camera_off` |
| `false` | usable | `mobile::pairing.no_search` |
| `false` | refused | `mobile::pairing.camera_off_no_search` |

### And what it says when the accept cannot be delivered

The same rule governs the *send* failure, which had escaped it. The poll
re-emits the responder-accept and reports a throw with
`mobile::pairing.errors.relay_unreachable` — "Make sure both are on the same
network and sync is enabled on the desktop." On the iPhone this was measured
on, both claims were true and checkable: phone `192.168.178.124` and desktop
`192.168.178.119` on one /24, `sync:serve` listening on `*:51337`, seven
interfaces advertising `did=…`, firewall off, `/pair/frame` answering. The
cause was on this side, and the line named the far one.

`MobilePairingScan::undeliveredAcceptKey()` now asks
`PairingGateway::hadAnyRoadTo()` — a browse this device can run, a configured
relay, or an address the code carried — and falls back to
`mobile::pairing.errors.no_road_home` when the answer is none. That line says
this device cannot search and the code carried no address, and asks for a
fresh code, which is the one action that helps.

The permission state itself is not readable. iOS exposes no API for whether
local-network access was granted, so the app cannot wait for the prompt to be
answered before it reports, and nothing on that surface may claim to know why
the silence happened.

### Answering the prompt does not make the search work

`no_peer_answered_ios` has been photographed on a real iPhone printed *under*
iOS's own still-open "Allow Beatrax to find devices on local networks?" alert,
which reads as the system offering the exact capability the app has just called
impossible. It is not. The alert fires *because* the app sent a multicast
datagram; the send is a side effect of the browse, not a sign that the browse
can succeed. **Allow** was tapped on the device and the identical code
resubmitted, and the identical message came back. Without
`com.apple.developer.networking.multicast` iOS drops outgoing multicast whether
or not local-network access was granted, so "just answer the prompt" is not a
theory this page leaves open, and the line is accurate as written.

### Said before the code is typed, not after

`runtimeReach()` is a platform check and a config read with no I/O, so the
verdict is knowable when the screen is drawn. It used to be consulted only from
`nothingAnsweredKey()`, which runs after a submit — the reader typed
thirty-two base-32 characters of a code and only then learned there was nothing
to find the peer with. `MobilePairingScan::render()` reads it as well and hands
the view `typedCodeCanFindPeer`, which raises the notice above on arrival and
qualifies the "Enter code instead" control on the camera step, where it is
offered. Qualified, not hidden: with the camera refused that control is the
reader's only remaining affordance, and removing it strands them harder than an
equal-looking choice that cannot work.

## Telling "nobody is there" from "I cannot look"

`PeerDiscovery::browse()` returns `[]` for both, and they want opposite things
said to a reader. `PeerDiscovery::reach()` splits them, returning
`Modules\Sync\Public\Enums\LanDiscoveryReach`:

| Case | Means | `silenceMeansNoPeers()` |
|---|---|---|
| `Available` | the question reached the network | `true` |
| `Unsupported` | the question never left this device | `false` |

`MulticastMdnsQuery::runtimeReach()` reads the platform and then the
declaration: on iOS it is `Unsupported` unless
`config('nativephp.entitlements')` carries
`com.apple.developer.networking.multicast`. A shipped iOS build carrying that
entitlement is proof Apple granted it, because declaring it ungranted stops the
build at signing rather than at runtime — so the declaration is a sound stand-in
for the grant, and it is the only thing on the device that can be read at all.

**This is what makes the maintenance obligation above mechanical.** The day the
grant lands and `IOS_MULTICAST_ENTITLEMENT` is switched on, `reach()` becomes
`Available` on iOS by itself. A screen selecting its copy on the reach rather
than on `UserDataPathService::platform()` stops showing the iPhone-specific line
that same day, with nothing to remember and nothing to delete.

The reach is also lowered by observation — a socket that would not open, a
question that could not be encoded — but never raised. A send the kernel
accepted proves nothing on a platform that drops the datagram afterwards, and
**iOS drops it silently**: `stream_socket_sendto()` is not known to fail there,
which is exactly why the platform verdict, not the send result, is doing the
work.

`PairingGateway::lanDiscoveryReach()` is the cross-module read, deliberately on
the object `AcceptsPairingCode` already injects and next to the
`discoverInitiatorOnLan()` whose `NoPeerReached` it explains. Reading it adds no
dependency: `typedCodeIdentity()` already holds the gateway it would pass.

```php
private function nothingAnsweredKey(PairingGateway $gateway): string
{
    if ($gateway->lanDiscoveryReach()->silenceMeansNoPeers()) {
        return 'mobile::pairing.errors.no_peer_answered';
    }

    return $this->cameraUnavailableNotice
        ? 'mobile::pairing.errors.no_peer_answered_camera_off'
        : 'mobile::pairing.errors.no_peer_answered_ios';
}
```

The `_ios` key name becomes the wrong name at that point — nothing about the
line is iOS-specific any more, only "the search did not run here". Renaming it
is the copy owner's call, not this page's.

## The NWBrowser road: reachable, and what is still missing

`NWBrowser` needs no multicast entitlement, and `NSBonjourServices` is already
declared. The question was whether PHP in this app can reach it at all. It can,
and the mechanism is not FFI or HTTP:

- `nativephp/mobile` 4.1.0 ships a C extension compiled into the app's
  `libphp.a`. `nm` on `mobile-app/nativephp/ios/Libraries/*/libphp.a` shows
  `nativephp.o` **defining** `_zif_nativephp_call` and `_zif_nativephp_can`, and
  leaving `_NativePHPCall` / `_NativePHPCan` undefined for the app target.
- Those are Swift `@_cdecl` symbols in
  `resources/xcode/NativePHP/Bridge/BridgeRouter.swift`. `NativePHPCall` looks
  the name up in `BridgeFunctionRegistry`, parses the JSON parameters, runs
  `BridgeFunction.execute()` **synchronously**, and returns a JSON string.
- A plugin is a Composer package of `"type": "nativephp-plugin"` carrying a
  `nativephp.json` manifest and `resources/ios/*.swift`. `IOSPluginCompiler`
  copies the Swift into the generated Xcode project and generates the
  `registry.register(...)` calls.
- **There is already a first-party one in this repo**:
  `mobile-app/nativephp-plugins/biometric-vault/`, allowlisted in
  `mobile-app/app/Providers/NativeServiceProvider.php`. Its generated
  registration lines are present in the built project. The mechanism is proven
  here, not merely documented.

So the PHP half was built and is tested: `BonjourBridgeQuery` speaks
`Discovery.Browse` over the `NativeBridge` seam and reports `Unsupported`
whenever the shell registered no such function — which is every build today.

**The Swift half is not written.** `NWBrowser` alone does not return what
`DiscoveredPeer` needs: `NWBrowser.Results` are
`NWEndpoint.service(name:type:domain:interface:)` — an instance name, not an
address. `DiscoveredPeer` needs host and port, so each result has to be resolved
as well, either through an `NWConnection` and its `currentPath.remoteEndpoint`,
or through `NetService.resolve(withTimeout:)`. That resolution step, not the
browse, is the part most likely to be wrong on the first attempt, and nothing
short of a real device can exercise it.

### What building it takes

1. Scaffold a plugin beside `biometric-vault` declaring one bridge function,
   `Discovery.Browse`, taking `serviceType` and `timeoutSeconds`.
2. Implement it in Swift as a `BridgeFunction` whose `execute()` runs an
   `NWBrowser` for the requested timeout on a background queue, resolves each
   result to a host and port, reads `did=` from the TXT record, and returns
   `["peers": [["deviceId": …, "host": …, "port": …], …]]`. `execute()` is
   synchronous, so it must block on a semaphore — acceptable, because the PHP
   caller already blocks for the whole browse timeout.
3. Add its service provider to
   `mobile-app/app/Providers/NativeServiceProvider.php::plugins()`. Without that
   entry `PluginDiscovery` ignores the package entirely.
4. Build and run on a real iPhone alongside a desktop running `sync:serve`,
   then type a pairing code on the phone.

Nothing above the transport changes. `SyncServiceProvider::discovery()` selects
`BonjourBridgeQuery` the moment `nativephp_can('Discovery.Browse')` answers yes.

### The one thing this road could tell us that no other can

The page above records that iOS exposes no API for whether local-network access
was granted. That stays true of any API the app can call directly — but
`NWBrowser` reports it *indirectly*. A browser started while the permission is
denied does not simply find nothing: it enters `.waiting` with a policy-denied
`NWError` rather than reaching `.ready`. A `Discovery.Browse` implementation
that surfaces that state could return a third answer — "the reader said no" —
which today's raw socket cannot produce and which the copy is therefore
forbidden from claiming.

**Unverified.** This is Apple's documented browser behaviour, not something
observed on the iPhone in this repo, and nothing should be built on it until a
real device has shown that state arriving. `LanDiscoveryReach` has two cases on
purpose; a third belongs there only once something can genuinely produce it.

### What rests on evidence, and what does not

**Read off the shipped artefacts and pinned by tests**: the symbol table of the
shipped `libphp.a`; the `@_cdecl` entry points; that a Beatrax-authored plugin
already flows through `IOSPluginCompiler` into the generated project; the
response envelope (on success `BridgeResponse.success()` returns the function's
data dict *unwrapped*, so the `{status, data}` shape in the bridge README is
wrong for the success case); and every PHP behaviour described above.

**Unverified on a device**: that `NWBrowser` returns Beatrax's advertisement on a real
iPhone; that resolution yields a dialable host and port; that blocking a bridge
call for two seconds is safe on device; and whether `stream_socket_sendto()` to
a multicast address reports failure on iOS or succeeds and is dropped. The last
one is why `runtimeReach()` reads the platform rather than the send result.

## Verifying

Set `IOS_MULTICAST_ENTITLEMENT=true` in `mobile-app/.env`, build, then read the
binary rather than the source:

```sh
codesign -d --entitlements - \
  ~/Library/Developer/Xcode/DerivedData/NativePHP-*/Build/Products/*/NativePHP.app
```

`com.apple.developer.networking.multicast` must appear there. Reading
`mobile-app/nativephp/ios/NativePHP/NativePHP.entitlements` proves nothing on
its own — that file is rewritten twice during a build.
