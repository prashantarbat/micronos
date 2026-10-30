# Contributing to Micron OS

Thank you for helping. Bug reports, hardware test results, documentation fixes
and code are all welcome.

## Ground rules

- Be kind and assume good intent. Everyone here follows the
  [Code of Conduct](CODE_OF_CONDUCT.md).
- Never report a security problem in a public issue. Follow
  [SECURITY.md](SECURITY.md) instead.
- By contributing you agree that your work is licensed under the
  [Apache License 2.0](LICENSE), the same as the rest of the project.

## Reporting a bug

Open an issue with the **Bug report** template and include:

- the Micron OS version (`microctl version`, or **System → About**),
- the hardware or hypervisor and the NICs involved,
- what you did, what you expected and what happened,
- relevant output of `microctl doctor` and the log lines around the failure.

Remove public IP addresses, passwords, keys and certificates before posting.

## Development setup

Requirements: PHP 8.2 or newer (the appliance ships 8.4), Composer, Python 3.11
or newer, and SQLite.

```bash
composer install
cp env .env                      # then set CI_ENVIRONMENT = development
php spark key:generate           # writes encryption.key into .env
php spark migrate --all
php spark db:seed InitialApplianceSeeder
php spark db:seed RolesPermissionsSeeder
php spark serve
```

The web UI talks to the `micrond` daemon over `/run/micron/micrond.sock`. On a
development machine without the daemon, pages fall back to the mock responses
in the IPC client.

## Before you open a pull request

Run every check the CI runs. All of them must pass:

```bash
php vendor/bin/phpunit --no-coverage
for t in tests/test_*.py; do python3 "$t" || echo "FAILED: $t"; done
for t in tests/installer/*.sh; do bash "$t" || echo "FAILED: $t"; done
python3 tools/rpc_inventory.py   # "called but not implemented" must be 0
```

Then:

1. Branch from `main` and keep one change per pull request.
2. Add or update tests for what you changed.
3. Update the documentation in `docs/` when behaviour or a screen changes.
4. Add a line under **Unreleased** in [CHANGELOG.md](CHANGELOG.md).
5. Fill in the pull request template.

## Code style

- PHP: PSR-12, CodeIgniter 4 conventions, typed properties and return types.
- Python (`micrond`, CLI): PEP 8, standard library first, no blocking calls on
  the event loop.
- Every privileged action goes through a `micrond` RPC method. The web
  application never runs shell commands as root itself.
- Keep generated configuration (nftables, FRR, nginx, strongSwan) in the
  compiler classes under `app/Libraries/`, with a test for the output.
- New source files start with `// SPDX-License-Identifier: Apache-2.0` (or `#`
  for Python and shell).

## Developer Certificate of Origin

Micron OS uses the [Developer Certificate of Origin 1.1](https://developercertificate.org/)
instead of a contributor licence agreement. Sign off every commit to certify
that you wrote the change or have the right to submit it under the project's
licence:

```bash
git commit -s -m "firewall: fix schedule matching across midnight"
```

This adds a `Signed-off-by: Your Name <you@example.com>` line. Pull requests
with unsigned commits cannot be merged.

## Commit messages

Use a short area prefix and the imperative mood, for example
`vpn: rotate WireGuard keys without dropping peers`. Explain *why* in the body
when it is not obvious.
