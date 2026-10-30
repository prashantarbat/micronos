# Third-party licences

Micron OS is licensed under the [Apache License 2.0](LICENSE). It bundles or
depends on the components below, each under its own licence. Keep this list in
step with `composer.lock`, `public/assets/vendor/README.md` and
`public/assets/fonts/README.md` whenever a component is added, removed or
upgraded.

## Bundled in this repository (served by the appliance)

| Component | Version | Location | Licence | Licence text |
| :--- | :--- | :--- | :--- | :--- |
| guacamole-common-js | 1.5.0 | `public/assets/vendor/guacamole/` | Apache-2.0 | `public/assets/vendor/guacamole/LICENSE.guacamole-common-js` (see also NOTICE) |
| @xterm/xterm | 5.5.0 | `public/assets/vendor/xterm/` | MIT | `public/assets/vendor/xterm/LICENSE.xterm` |
| @xterm/addon-fit | 0.10.0 | `public/assets/vendor/xterm/` | MIT | `public/assets/vendor/xterm/LICENSE.addon-fit` |
| marked | 15.0.7 | `public/assets/vendor/marked.min.js` | MIT | <https://github.com/markedjs/marked/blob/master/LICENSE.md> |
| highlight.js | 11.9.0 | `public/assets/vendor/highlight.min.js` | BSD-3-Clause | <https://github.com/highlightjs/highlight.js/blob/main/LICENSE> |
| Inter (variable) | 4.x | `public/assets/fonts/inter-*.woff2` | OFL-1.1 | <https://github.com/rsms/inter/blob/master/LICENSE.txt> |
| JetBrains Mono (variable) | 2.x | `public/assets/fonts/jetbrains-mono-*.woff2` | OFL-1.1 | <https://github.com/JetBrains/JetBrainsMono/blob/master/OFL.txt> |

## PHP runtime dependencies (installed by Composer)

| Package | Version | Licence |
| :--- | :--- | :--- |
| codeigniter4/framework | 4.7.4 | MIT |
| laminas/laminas-escaper | 2.18.0 | BSD-3-Clause |
| psr/log | 3.0.2 | MIT |

Development-only packages (PHPUnit, Faker, vfsStream) are not shipped in the
ISO. Regenerate this table with `composer licenses --no-dev`.

## Operating system packages in the ISO

The ISO is built with Debian `live-build` and installs Debian packages
unmodified, each under its own licence (GPL-2.0, GPL-3.0, LGPL, BSD, MIT,
Apache-2.0 and others). The full list for a given build is in the image at
`/usr/share/doc/*/copyright`. See README.md, "Source code for the ISO image".

## Removed brand fonts

Earlier development builds bundled `public/assets/fonts/OPENDisplay-*.woff2`
and `OPENText-*.woff2`, third-party brand fonts with no licence that permits
redistribution. `public/css/micronos.css` no longer references them (the
Micron OS theme uses Inter), and the files must be deleted and purged from git
history before the repository is made public.
