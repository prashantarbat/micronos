# Security policy

Micron OS is a network security appliance, so we treat vulnerability reports
as the highest priority.

## Supported versions

| Version | Community Edition | LTS (support subscribers) |
| :--- | :--- | :--- |
| Latest stable release (1.0.x) | Yes: security fixes | Yes |
| Previous stable release | No, please upgrade | Yes, while its LTS term runs |
| Designated LTS releases | Until the next stable release | 3 years from release |
| 1.0.0 release candidates | Until 1.0.0 is released | No |
| 0.x development builds | No | No |

Every security fix is released to the Community Edition. LTS backports to
older releases are part of paid support; see [SUPPORT.md](SUPPORT.md).

## Reporting a vulnerability

**Please do not open a public GitHub issue for security problems.**

Report privately by either:

- GitHub: **Security → Report a vulnerability** on this repository (private
  vulnerability reporting), or
- email: **prashant.arbat@gmail.com** with the subject `SECURITY: <short summary>`.

Include the affected version, the component (web UI, `micrond`, gateway,
installer, ISO), steps to reproduce, and the impact you expect. A proof of
concept helps but is not required.

## What happens next

| Step | Target time |
| :--- | :--- |
| Acknowledgement of your report | 3 working days |
| Initial assessment and severity | 10 working days |
| Fix released for critical and high issues | 30 days |
| Public disclosure | When the fix ships, or 90 days after the report, whichever is first |

We will credit you in the release notes and the advisory unless you ask us not
to. Advisories are published under **Security → Advisories** on GitHub.

## Scope

In scope: the Micron OS source in this repository and the official ISO images
published on its Releases page.

Out of scope: vulnerabilities in upstream Debian packages that are already
public (report those to Debian; we pick up their fixes through updates),
findings that need physical console access to an appliance you already
administer, and denial of service by flooding traffic.

## Hardening your appliance

See [docs/SECURITY_HARDENING.md](docs/SECURITY_HARDENING.md) for deployment
guidance: changing the default credentials at first login, restricting
management access, enabling two-step verification, and putting the Clientless
Gateway under firewall control.
