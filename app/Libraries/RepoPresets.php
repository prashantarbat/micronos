<?php

namespace App\Libraries;

/**
 * Preset third-party repositories (Plugin Spec §5.2, P3).
 *
 * A Class C plugin needs a *vendor* apt repository: the distribution does not
 * ship ZeroTier or NetBird. The spec's trust model says the signing
 * key's fingerprint is the anchor and the operator states it in advance —
 * "image-shipped for presets, declared out of band for operator-registered
 * ones". This class is the image-shipped half: a preset carries the URL, the
 * suite, the **fingerprint of the key that signs the repository's metadata**,
 * and the vendor's published public key itself, so adopting one needs no
 * network round-trip and no typing.
 *
 * Adopting a preset is not a bypass. It goes through exactly the same
 * validation and the same apply as a hand-entered repository
 * ({@see RepoRegistry::seedPreset()} calls `normalize()`), the key material is
 * still checked against the declared fingerprint by the daemon, and the
 * repository is still pinned below the distribution. What the preset removes is
 * the opportunity to paste a *wrong* fingerprint.
 *
 * ## How a preset's fingerprint was established
 *
 * Not from the key file. Each preset was verified the way an operator must
 * verify it: the vendor's published key was fetched, its OpenPGP v4 fingerprint
 * computed independently of the key file's own claims, and the repository's
 * `dists/<suite>/InRelease` was verified against that key, with gpg's `VALIDSIG`
 * signer compared to the fingerprint recorded here. `verified_at` records when.
 *
 * The shipped trust data is machine-checked too: `tests/test_repo_presets.py`
 * recomputes each preset's fingerprint from the embedded key and refuses a
 * mismatch, so a preset cannot drift from its key material unnoticed. The other
 * half — that the vendor still signs its metadata with that key — is
 * `tests/test_repo_presets_live.py --live`, an opt-in harness that fetches each
 * preset's `InRelease` and requires the declared fingerprint to be the signer.
 *
 * That check is offered on the appliance as well, from the Repositories screen:
 * the per-preset *Verify against vendor* action ({@see \App\Controllers\Packages::testPreset()})
 * fetches the vendor's metadata and requires the signer to be the fingerprint
 * declared here. It reads this shipped data rather than a stored row, so an
 * operator can confirm what the image claims *before* registering the preset,
 * and re-confirm it afterwards however they have since edited the row — which is
 * the whole point of a preset: the trust anchor is the image's, not the form's.
 */
final class RepoPresets
{
    /**
     * NetBird's published repository key (`https://pkgs.netbird.io/debian/public.key`).
     *
     * Public data, not a secret — which is why it is safe to ship and to back up
     * with the repository row. It holds exactly one primary key plus that key's
     * encryption subkey; the **primary** is what clearsigns the repository's
     * `InRelease`, and it is the primary's fingerprint {@see PRESETS} declares.
     * The daemon exports only the key whose fingerprint it verified, so a vendor
     * file that published extra keys could not widen what this repository is
     * trusted for.
     *
     * One provenance detail worth knowing when cross-checking by hand: the UID on
     * this key is `iretrustee <dev@wiretrustee.com>` — NetBird's former name. A key
     * whose UID does not say "NetBird" is the right key, and the fingerprint is
     * the thing to compare, not the name.
     */
    public const NETBIRD_KEY = <<<'ARMOR'
-----BEGIN PGP PUBLIC KEY BLOCK-----

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
0wz0XYWoEFCVdIt9Rw==
=63js
-----END PGP PUBLIC KEY BLOCK-----
ARMOR;

    /**
     * ZeroTier's published repository key.
     *
     * Fetched from `https://download.zerotier.com/contact%40zerotier.com.gpg`
     * (the vendor redirects that name to the same block the ZeroTierOne
     * repository publishes in `doc/`). The copy here is a `gpg --armor --export`
     * of the key that verified the repository, so it carries no armour header of
     * its own — the same canonical form as {@see NETBIRD_KEY} — and only the
     * primary key and its encryption subkey. The **primary** is what clearsigns
     * the repository's `InRelease`, and it is the primary's fingerprint
     * {@see PRESETS} declares. The daemon exports only the key whose fingerprint
     * it verified, so a vendor file that published extra keys could not widen
     * what this repository is trusted for.
     *
     * Unlike the NetBird key this one is not anonymous: the UID reads
     * `ZeroTier, Inc. (ZeroTier Support and Release Signing Key)
     * <contact@zerotier.com>`. A familiar name is not evidence, though — the
     * fingerprint is the thing to compare, and the release signature is the
     * thing that proves it.
     */
    public const ZEROTIER_KEY = <<<'ARMOR'
-----BEGIN PGP PUBLIC KEY BLOCK-----

mQINBFdQq7oBEADEVhyRiaL8dEjMPlI/idO8tA7adjhfvejxrJ3Axxi9YIuIKhWU
5hNjDjZAiV9iSCMfJN3TjC3EDA+7nFyU6nDKeAMkXPbaPk7ti+Tb1nA4TJsBfBlm
CC14aGWLItpp8sI00FUzorxLWRmU4kOkrRUJCq2kAMzbYWmHs0hHkWmvj8gGu6mJ
WU3sDIjvdsm3hlgtqr9grPEnj+gA7xetGs3oIfp6YDKymGAV49HZmVAvSeoqfL1p
pEKlNQ1aO9uNfHLdx6+4pS1miyo7D1s7ru2IcqhTDhg40cHTL/VldC3d8vXRFLIi
Uo2tFZ6J1jyQP5c1K4rTpw3UNVne3ob7uCME+T1+ePeuM5Y/cpcCvAhJhO0rrlr0
dP3lOKrVdZg4qhtFAspC85ivcuxWNWnfTOBrgnvxCA1fmBX+MLNUEDsuu55LBNQT
5+WyrSchSlsczq+9EdomILhixUflDCShHs+Efvh7li6Pg56fwjEfj9DJYFhRvEvQ
7GZ7xtysFzx4AYD4/g5kCDsMTbc9W4Jv+JrMt3JsXt2zqwI0P4R1cIAu0J6OZ4Xa
dJ7Ci1WisQuJRcCUtBTUxcYAClNGeors5Nhl4zDrNIM7zIJp+GfPYdWKVSuW10mC
r3OS9QctMSeVPX/KE85TexeRtmyd4zUdio49+WKgoBhM8Z9MpTaafn2OPQARAQAB
tFBaZXJvVGllciwgSW5jLiAoWmVyb1RpZXIgU3VwcG9ydCBhbmQgUmVsZWFzZSBT
aWduaW5nIEtleSkgPGNvbnRhY3RAemVyb3RpZXIuY29tPokCNwQTAQoAIQUCV1Cr
ugIbAwULCQgHAwUVCgkICwUWAgMBAAIeAQIXgAAKCRAWVxmII+UqYViGEACnC3+3
lRzfv7f7JLWo23FSHjlF3IiWfYd+47BLDx706SDih1H6Qt8CqRy706bWbtictEJ/
xTaWgTEDzY/lRalYO5NAFTgK9h2zBP1t8zdEA/rmtVPOWOzd6jr0q3l3pKQTeMF0
6g+uaMDG1OkBz6MCwdg9counz6oa8OHK76tXNIBEnGOPBW375z1O+ExyddQOHDcS
IIsUlFmtIL1yBa7Q5NSfLofPLfS0/o2FItn0riSaAh866nXHynQemjTrqkUxf5On
65RLM+AJQaEkX17vDlsSljHrtYLKrhEueqeq50e89c2Ya4ucmSVeC9lrSqfyvGOO
P3aT/hrmeE9XBf7a9vozq7XhtViEC/ZSd1/z/oeypv4QYenfw8CtXP5bW1mKNK/M
8xnrnYwo9BUMclX2ZAvu1rTyiUvGre9fEGfhlS0rjmCgYfMgBZ+R/bFGiNdn6gAd
PSY/8fP8KFZl0xUzh2EnWe/bptoZ67CKkDbVZnfWtuKA0Ui7anitkjZiv+6wanv4
+5A3k/H3D4JofIjRNgx/gdVPhJfWjAoutIgGeIWrkfcAP9EpsR5swyc4KuE6kJ/Y
wXXVDQiju0xE1EdNx/S1UOeq0EHhOFqazuu00ojATekUPWenNjPWIjBYQ0Ag4ycL
KU558PFLzqYaHphdWYgxfGR+XSgzVTN1r7lW87kCDQRXUKu6ARAA2wWOywNMzEiP
ZK6CqLYGZqrpfx+drOxSowwfwjP3odcK8shR/3sxOmYVqZi0XVZtb9aJVz578rNb
e4Vfugql1Yt6w3V84z/mtfj6ZbTOOU5yAGZQixm6fkXAnpG5Eer/C8Aw8dH1EreP
Na1gIVcUzlpg2Ql23qjr5LqvGtUB4BqJSF4X8efNi/y0hj/GaivUMqCF6+Vvh3GG
fhvzhgBPku/5wK2XwBL9BELqaQ/tWOXuztMw0xFH/De75IH3LIvQYCuv1pnM4hJL
XYnpAGAWfmFtmXNnPVon6g542Z6c0G/qi657xA5vr6OSSbazDJXNiHXhgBYEzRrH
napcohTQwFKEA3Q4iftrsTDX/eZVTrO9x6qKxwoBVTGwSE52InWAxkkcnZM6tkfV
n7Ukc0oixZ6E70Svls27zFgaWbUFJQ6JFoC6h+5AYbaga6DwKCYOP3AR+q0ZkcH/
oJIdvKuhF9zDZbQhd76b4gK3YXnMpVsj9sQ9P23gh61RkAQ1HIlGOBrHS/XYcvpk
DcfIlJXKC3V1ggrG+BpKu46kiiYmRR1/yM0EXH2n99XhLNSxxFxxWhjyw8RcR6iG
ovDxWAULW+bJHjaNJdgb8Kab7j2nT2odUjUHMP42uLJgvS5LgRn39IvtzjoScAqg
8I817m8yLU/91D2f5qmJIwFI6ELwImkAEQEAAYkCHwQYAQoACQUCV1CrugIbDAAK
CRAWVxmII+UqYWSSEACxaR/hhr8xUIXkIV52BeD+2BOS8FNOi0aM67L4fEVplrsV
Op9fvAnUNmoiQo+RFdUdaD2Rpq+yUjQHHbj92mlk6Cmaon46wU+5bAWGYpV1Uf+o
wbKw1Xv83Uj9uHo7zv9WDtOUXUiTe/S792icTfRYrKbwkfI8iCltgNhTQNX0lFX/
Sr2y1/dGCTCMEuA/ClqGKCm9lIYdu+4z32V9VXTSX85DsUjLOCO/hl9SHaelJgmi
IJzRY1XLbNDK4IH5eWtbaprkTNIGt00QhsnM5w+rn1tO80giSxXFpKBE+/pAx8PQ
RdVFzxHtTUGMCkZcgOJolk8y+DJWtX8fP+3a4Vq11a3qKJ19VXk3qnuC1aeW7OQF
j6ISyHsNNsnBw5BRaS5tdrpLXw6Z7TKr1eq+FylmoOK0pIw5xOdRmSVoFm4lVcI5
e5EwB7IIRF00IFqrXe8dCT0oDT9RXc6CNh6GIs9D9YKwDPRD/NKQlYoegfa13Jz7
S3RIXtOXudT1+A1kaBpGKnpXOYD3w7jW2l0zAd6a53AAGy4SnL1ac4cml76NIWiF
m2KYzvMJZBk5dAtFa0SgLK4fg8X6Ygoo9E0JsXxSrW9I1JVfo6Ia//YOBMtt4XuN
Awqahjkq87yxOYYTnJmr2OZtQuFboymfMhNqj3G2DYmZ/ZIXXPgwHx0fnd3R0Q==
=JgAv
-----END PGP PUBLIC KEY BLOCK-----
ARMOR;

    /**
     * Preset id => definition.
     *
     * @var array<string, array<string, mixed>>
     */
    public const PRESETS = [
        'netbird' => [
            'id'       => 'netbird',
            'name'     => 'NetBird (pkgs.netbird.io)',
            'url'      => 'https://pkgs.netbird.io/debian',
            // Suite `stable`, not a codename: the vendor publishes one suite for
            // every Debian release, so the preset does not need the appliance's
            // own codename to stay correct.
            'suite'         => 'stable',
            'components'    => ['main'],
            'architectures' => ['amd64', 'arm64'],
            'fingerprint'   => 'EFE37DF047DF7CCDF1FC54FA83F79AD029778355',
            'key_url'       => 'https://pkgs.netbird.io/debian/public.key',
            'key_armor'     => self::NETBIRD_KEY,
            'plugins'       => ['netbird'],
            'docs'          => 'https://docs.netbird.io/get-started/install/linux',
            // Where a human re-derives the fingerprint from. Not stored as a
            // fetch instruction — the preset ships the key itself.
            'key_source_url' => 'https://pkgs.netbird.io/debian/public.key',
            'verified_at'   => '2026-10-06',
            'note'          => 'Verified live: dists/stable/InRelease is clearsigned by the declared key '
                . '(gpg VALIDSIG EFE37DF047DF7CCDF1FC54FA83F79AD029778355). The key\'s UID reads '
                . '"iretrustee <dev@wiretrustee.com>" — NetBird\'s former name — so compare the '
                . 'fingerprint above, not the name; 5FA4DC0963D58E3AF4CC687CD3763BC13139C64F is that '
                . 'key\'s encryption subkey and does not sign the metadata.',
        ],
        'zerotier' => [
            'id'       => 'zerotier',
            'name'     => 'ZeroTier One (download.zerotier.com)',
            'url'      => 'https://download.zerotier.com/debian/trixie',
            // Unlike NetBird's release-independent `stable`, ZeroTier publishes
            // one distribution *per Debian codename* (`.../debian/bookworm`,
            // `.../debian/trixie`, …) and there is no rolling suite to point at.
            // The preset is therefore image-specific by necessity: the image is
            // Debian 13 "trixie", so the preset names the trixie distribution,
            // and a future image on a newer codename must ship an updated preset
            // rather than silently inheriting this one. The daemon's
            // `plugin.repo_test` surfaces the mismatch as a warning if it ever
            // happens — the release would declare a suite the repository is not
            // configured for.
            'suite'         => 'trixie',
            'components'    => ['main'],
            // The release publishes amd64 arm64 armhf i386 ppc64el s390x; declaring
            // the two the appliance builds for keeps apt from spending a request on
            // metadata the image can never use.
            'architectures' => ['amd64', 'arm64'],
            'fingerprint'   => '74A5E9C458E1A431F1DA57A71657198823E52A61',
            'key_url'       => 'https://download.zerotier.com/contact%40zerotier.com.gpg',
            'key_armor'     => self::ZEROTIER_KEY,
            'plugins'       => ['zerotier'],
            'docs'          => 'https://docs.zerotier.com/',
            // Where a human re-derives the fingerprint from. Not stored as a
            // fetch instruction — the preset ships the key itself.
            'key_source_url' => 'https://download.zerotier.com/contact%40zerotier.com.gpg',
            'verified_at'   => '2026-10-09',
            'note'          => 'Verified live: dists/trixie/InRelease is clearsigned by the declared key '
                . '(gpg VALIDSIG 74A5E9C458E1A431F1DA57A71657198823E52A61, issued to "ZeroTier, Inc. '
                . '(ZeroTier Support and Release Signing Key) <contact@zerotier.com>"). The release '
                . 'advertises Suite/Codename "trixie", Components "main" and Architectures '
                . '"amd64 arm64 armhf i386 ppc64el s390x". 41CA9BC62B9FBDC669DB74225A4011580999FBE0 is that '
                . 'key\'s encryption subkey and does not sign the metadata. The vendor key is published '
                . 'outside the repository path (download.zerotier.com/contact@zerotier.com.gpg), so the '
                . 'key URL above is provenance, not a fetch instruction — the key travels inline.',
        ],
    ];

    /**
     * Every preset, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return self::PRESETS;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        return self::PRESETS[strtolower(trim($id))] ?? null;
    }

    /**
     * Presets that provide a repository for a plugin — the manifest's
     * `requires.repos` binding, read from the other side.
     *
     * @return list<array<string, mixed>>
     */
    public function for(string $pluginId): array
    {
        return array_values(array_filter(
            self::PRESETS,
            static fn (array $preset): bool => in_array($pluginId, (array) $preset['plugins'], true),
        ));
    }

    /**
     * Turn a preset into the input shape {@see RepoRegistry::save()} accepts, so
     * a preset is validated by the same grammars as any other repository.
     *
     * The key travels **inline**, never by URL. A repository accepts one or the
     * other, and for a preset the inline form is the stronger of the two: the
     * appliance never has to fetch key material from the same host whose
     * metadata it is being asked to trust. `key_url` in the preset data is kept
     * as provenance — where a human re-derives the fingerprint from — and is
     * shown on the Repositories page, not stored as a fetch instruction.
     *
     * `enabled` is false: shipping trust for a vendor is not the same as
     * fetching from that vendor, which stays an explicit operator action.
     *
     * @param array<string, mixed> $preset
     *
     * @return array<string, mixed>
     */
    public static function input(array $preset): array
    {
        return [
            'id'              => (string) $preset['id'],
            'name'            => (string) $preset['name'],
            'url'             => (string) $preset['url'],
            'suite'           => (string) $preset['suite'],
            'components'      => implode(',', (array) $preset['components']),
            'architectures'   => implode(',', (array) $preset['architectures']),
            'key_fingerprint' => (string) $preset['fingerprint'],
            'key_url'         => '',
            'key_armor'       => (string) $preset['key_armor'],
            // Disabled until the operator enables and applies it: shipping trust
            // for a vendor is not the same as fetching from that vendor.
            'enabled'         => false,
        ];
    }
}
