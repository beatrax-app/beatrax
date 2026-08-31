# Local development

Everything a contributor needs to clone the repo, get a local instance running,
work against the local SQLite database, and flip into developer mode when they
need the in-app debug surfaces.

The project is designed around the Docker dev toolchain (`docker compose run --rm
php …`) and a single local SQLite file. The supported developer workflow is "clone
the repo, build the Docker image, run the gates and the app through it" — no host
PHP, no Homebrew PHP, no Vagrant.

## Topics

| File | What it covers |
|---|---|
| [setup.md](setup.md) | Cloning the repo, Docker toolchain, first-time bootstrap, building the desktop bundle locally |
| [database.md](database.md) | Where the SQLite file lives, WAL mode, recommended GUIs |
| [troubleshooting.md](troubleshooting.md) | The recurring gotchas — PHP version, sodium, NativePHP bundle availability |
| [dev-mode.md](dev-mode.md) | Turning on developer mode and the surfaces it exposes |
| [rebasing-a-statement-fixture.md](rebasing-a-statement-fixture.md) | Getting a shipped statement fixture into the date windows the product reads, for hand-testing a build |
