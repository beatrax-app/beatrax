# NativePHP v2 facade-fake availability

A design note for whoever writes the native-chrome tests. NativePHP
`nativephp/desktop` 2.2.0 ships test fakes for only a subset of its
facades. Where a fake is PRESENT, a behavioral assertion can run
automated against the recorded fake state. Where a fake is ABSENT, the
facade dispatches straight to the Electron driver and cannot be
asserted headlessly — those assertions are marked `->todo()` and
deferred to manual UAT.

| Facade | Fake | Entry point | Notes |
|--------|------|-------------|-------|
| `Window` | PRESENT | `Native\Desktop\Facades\Window::fake()` swaps in `Native\Desktop\Fakes\WindowManagerFake` | Window open/close/resize calls are recorded on the fake and can be asserted directly. |
| `Menu` | ABSENT | — | No `fake()` method and no `*Fake` class in `Native\Desktop\Fakes`. App-menu construction must be asserted via the module's own `AppMenuBuilder` return value, with the live `Menu` facade call deferred to manual UAT. |
| `MenuBar` | NOT USED | — | The macOS menu-bar tray is created directly in the Electron main process via `scripts/nativephp_inject_persistent_tray.php`, NOT through NativePHP's `MenuBar` facade. The regression guard against accidentally reintroducing the facade is in `NativeAppServiceProviderTest` (asserts no POST to `menu-bar/create`); the persistent-tray injection itself is verified by `InjectPersistentTrayScriptTest`. The architectural rationale is in the `NativeAppServiceProvider` class docblock. |
| `Notification` | ABSENT | — | No `fake()` method and no `*Fake` class. Native notification dispatch is manual-UAT only. |

Fakes that DO ship in `Native\Desktop\Fakes` (2.2.0): `WindowManagerFake`,
`ChildProcessFake`, `GlobalShortcutFake`, `PowerMonitorFake`,
`QueueWorkerFake`, `ShellFake`.

Implication for native-chrome tests: the window-configuration test can
be fully automated via `Window::fake()`; the app-menu test asserts the
builder output (the module's own class) automatically and marks the
live-facade leg `->todo()`; the system-tray is verified through the
injection script's regression tests.
