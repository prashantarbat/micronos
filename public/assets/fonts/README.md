# Self-hosted web fonts

The UI must render with no route to the internet, so the appliance serves its own
typefaces instead of asking `fonts.googleapis.com` for them. `public/css/micronos.css`
imports `fonts.css` from here, and every page loads that stylesheet — including the
standalone sign-in and 2FA screens, which do not use the main layout.

| Family | Subsets | Weight axis | Files |
| :--- | :--- | :--- | :--- |
| Inter | latin, latin-ext, cyrillic, cyrillic-ext, greek, greek-ext, vietnamese | 300–800 | 7 |
| JetBrains Mono | latin, latin-ext, cyrillic, cyrillic-ext, greek, vietnamese | 400–500 | 6 |

Both families are **SIL Open Font License 1.1**. These are the *variable* builds —
one file per subset covers the whole weight range, which is why 13 files replace
the ~50 static files a discrete-weight request returns. Google's `unicode-range`
values are preserved, so a browser still downloads only the subsets it needs.

## Caching and the version token

`public/.htaccess` — and the nginx server block this appliance ships, documented
in `docs/ISO_CREATION_AND_INSTALLER_GUIDE.md` §6.1 — marks `/assets/fonts/` as
`Cache-Control: public, max-age=31536000, immutable`. That is only safe because
every URL under it is versioned: `fonts.css` and each `@font-face` `src` carry
`?v=1`, and so do the `@import` in `public/css/micronos.css` and the
`<link rel="preload">` in `app/Views/layouts/main.php`, `auth/login.php` and
`auth/totp.php`.

The token appears in **five** files, and `tests/app/Views/OfflineAssetsTest.php`
fails if they disagree. Bump `?v=` in all of them together whenever the files here
are replaced — otherwise a browser holding an immutable copy keeps rendering the
old typeface for a year.

The preload starts the primary subset (`inter-latin.woff2`) at HTML parse time
instead of after `micronos.css` and its `@import` have been fetched in turn. It
must use the exact same URL — query string included — as the `@font-face` rule,
or the browser downloads the font twice. Latin is the subset every page renders;
the rest stay lazily discovered through their `unicode-range`.

## Regenerating

`fonts.css` is generated, not hand-written, and the fonts are only re-downloaded
when the typefaces themselves change. The procedure:

```bash
# 1. Google serves woff2 + unicode-range only to a modern browser agent.
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
curl -fsS -A "$UA" \
  "https://fonts.googleapis.com/css2?family=Inter:wght@300..800&family=JetBrains+Mono:wght@400..500&display=swap" \
  -o /tmp/google-fonts.css

# 2. Download every file it names, into this directory...

# 3. ...then rewrite each `src: url(https://fonts.gstatic.com/...)` to the local
#    filename with the current version token, keeping the family, weight axis,
#    font-display and unicode-range declarations exactly as Google emitted them:
#
#      src: url(inter-latin.woff2?v=1) format('woff2');
#
# 4. Bump the token to the next number in all five files listed above.
```

Keep the `font-weight` ranges (`300 800`, `400 500`) rather than the design's
discrete weights: they are what makes the files variable, and any weight the
design uses falls inside them.

`tests/app/Views/OfflineAssetsTest.php` fails if a remote `url()` reappears in any
stylesheet, if `micronos.css` stops importing this file, or if a referenced
`.woff2` goes missing or is not a real woff2.
