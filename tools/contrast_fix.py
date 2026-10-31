#!/usr/bin/env python3
"""Lift each theme's --text-muted / --text-secondary until it clears WCAG AA
(4.5:1) against that theme's background and its surfaces. Prints a report;
with --write, rewrites public/css/micronos.css in place."""
import colorsys
import re
import sys

PATH = "public/css/micronos.css"

def hex_rgb(h):
    h = h.lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    return tuple(int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))

def lum(rgb):
    def f(c):
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = map(f, rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def contrast(a, b):
    la, lb = lum(a), lum(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)

def to_hex(rgb):
    return "#" + "".join(f"{round(c * 255):02x}" for c in rgb)

css = open(PATH, encoding="utf-8").read()
blocks = list(re.finditer(r"(?ms)^(:root|\[data-theme=\"[a-z-]+\"\])\s*\{(.*?)^\}", css))
changes = []
for m in blocks:
    body = m.group(2)
    tokens = dict(re.findall(r"(--[a-z0-9-]+):\s*(#[0-9a-fA-F]{3,6})\s*;", body))
    bgs = [tokens[k] for k in ("--color-bg", "--color-surface-1", "--color-surface-2", "--color-surface-3") if k in tokens]
    if not bgs:
        continue
    for tok in ("--text-muted", "--text-secondary"):
        if tok not in tokens:
            continue
        rgb = hex_rgb(tokens[tok])
        worst = min(contrast(rgb, hex_rgb(b)) for b in bgs)
        if worst >= 4.5:
            continue
        light_bg = lum(hex_rgb(bgs[0])) > 0.4
        h, light, s = colorsys.rgb_to_hls(*rgb)
        new = rgb
        for _ in range(200):
            light = max(0.0, light - 0.005) if light_bg else min(1.0, light + 0.005)
            new = colorsys.hls_to_rgb(h, light, s)
            if min(contrast(new, hex_rgb(b)) for b in bgs) >= 4.6:
                break
        changes.append((m.group(1), tok, tokens[tok], to_hex(new), round(worst, 2),
                        round(min(contrast(new, hex_rgb(b)) for b in bgs), 2), m.start(2), m.end(2)))

for sel, tok, old, new, before, after, *_ in changes:
    print(f"{sel:28s} {tok:16s} {old} -> {new}  ({before}:1 -> {after}:1)")
if "--write" in sys.argv:
    out = css
    for _sel, tok, old, new, _before, _after, start, end in sorted(changes, key=lambda c: -c[6]):
        body = out[start:end]
        body = re.sub(rf"({re.escape(tok)}:\s*){re.escape(old)}", rf"\g<1>{new}", body, count=1)
        out = out[:start] + body + out[end:]
    open(PATH, "w", encoding="utf-8").write(out)
    print(f"wrote {len(changes)} change(s)")
