# Vendored browser libraries

The documentation browser has to render on a management network with no route to
the internet, so these files are served by the appliance itself instead of from a
public CDN. `app/Views/docs/page.php` uses the documentation renderers; the
Web Terminal (`app/Views/terminal/index.php`) uses xterm.js, and the Clientless
Gateway viewer (`app/Views/ztna/viewer.php`) uses guacamole-common-js.

| File | Version | Upstream | Licence |
| :--- | :--- | :--- | :--- |
| `marked.min.js` | 15.0.7 | <https://github.com/markedjs/marked> | MIT |
| `highlight.min.js` | 11.9.0 | <https://github.com/highlightjs/highlight.js> | BSD-3-Clause |
| `xterm/xterm.js`, `xterm/xterm.css` | @xterm/xterm 5.5.0 | <https://github.com/xtermjs/xterm.js> | MIT (`xterm/LICENSE.xterm`) |
| `xterm/addon-fit.js` | @xterm/addon-fit 0.10.0 | <https://github.com/xtermjs/xterm.js> | MIT (`xterm/LICENSE.addon-fit`) |
| `guacamole/guacamole-common.js` | guacamole-common-js 1.5.0 | <https://github.com/apache/guacamole-client> | Apache-2.0 (`guacamole/LICENSE.guacamole-common-js`) |

`highlight.min.js` is the default build (the common-language bundle). No theme
stylesheet is vendored: the token colours are defined by `page.php` itself, so
swapping in a highlight.js theme would fight them.

## Updating

xterm.js comes from the npm registry (`npm pack @xterm/xterm@<v> @xterm/addon-fit@<v>`,
then copy `lib/xterm.js`, `css/xterm.css` and `lib/addon-fit.js` into `xterm/`).

guacamole-common-js is the upstream `guacamole-common-js/src/main/webapp/modules/*.js`
concatenated (as `npm pack guacamole-common-js@<v>` ships it in `dist/cjs/`), with the
trailing `module.exports` line removed so it loads as a plain browser script. Any 1.x
release talks to guacd 1.6.

Download the new build and replace the file, then update the version row above:

```bash
curl -fsS -o public/assets/vendor/marked.min.js \
  "https://cdn.jsdelivr.net/npm/marked@<version>/marked.min.js"
curl -fsS -o public/assets/vendor/highlight.min.js \
  "https://cdnjs.cloudflare.com/ajax/libs/highlight.js/<version>/highlight.min.js"
```

The view appends `?v=<filemtime>` to both URLs, so replacing a file busts browser
caches with no further change. `DocsTest` fails if either file is missing, is
implausibly small, or loses the upstream banner that identifies it.
