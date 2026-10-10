"""Strict JSON and base64 decoding, shared by every harness.

One place that defines how the harnesses decode, so a lenient decoder cannot
creep back into a single harness while the others stay strict:

  * `strict_json_loads` — `json.loads` with the non-standard `NaN` /
    `Infinity` / `-Infinity` extensions disabled (RFC 8259). `json.loads`
    already rejects trailing data, so that is not re-implemented here.
  * `strict_json_dumps` — `json.dumps` with `allow_nan=False`, so a fixture a
    harness builds can never carry a non-standard constant either.
  * `strict_b64decode` / `armor_payload` — base64 decoding with `validate=True`,
    after the ASCII-armour CRC24 checksum line (which is not base64 payload) is
    stripped.
  * `crc24` / `crc24_armor_line` / `pgp_primary_fingerprints` — the OpenPGP
    radix-64 checksum (RFC 4880 §6.1) and v4 fingerprint, used to verify shipped
    key material against its declared fingerprint.
"""

import base64
import hashlib
import json

BEGIN_ARMOR = "-----BEGIN PGP PUBLIC KEY BLOCK-----"
END_ARMOR = "-----END PGP PUBLIC KEY BLOCK-----"


def reject_nonstandard_json_constant(token):
    """Rejects the `NaN` / `Infinity` / `-Infinity` forms `json.loads` accepts."""
    raise ValueError(f"non-standard JSON constant rejected: {token}")


def strict_json_loads(text):
    """`json.loads` with the non-standard NaN/Infinity extensions disabled."""
    return json.loads(text, parse_constant=reject_nonstandard_json_constant)


def strict_json_dumps(obj, **kwargs):
    """`json.dumps` that refuses to emit a non-standard NaN/Infinity constant."""
    return json.dumps(obj, allow_nan=False, **kwargs)


def strict_b64decode(text):
    """`base64.b64decode` that rejects any character outside the alphabet.

    The default (`validate=False`) silently discards junk — trailing data, a
    stray checksum line, a truncated block — so a corrupt payload can decode to
    something plausible. `validate=True` turns that into an error instead.
    """
    return base64.b64decode(text, validate=True)


def armor_payload(armor_text):
    """Extracts the base64 payload from an ASCII-armored key block.

    `gpg --armor` appends a CRC24 integrity checksum as a final line beginning
    with `=`. That line is not part of the base64 payload, so it is dropped
    before decoding; decoding it would be either silently ignored (lenient
    decoder) or rejected as "Incorrect padding" (strict decoder).

    Returns `(blob, checksum)` — the decoded bytes and the checksum line when
    the armour carried one (`None` otherwise).
    """
    body = armor_text.split(BEGIN_ARMOR, 1)[1]
    body = body.split(END_ARMOR, 1)[0]

    checksum = None
    payload_lines = []
    for line in body.splitlines():
        stripped = line.strip()
        if not stripped:
            continue
        if stripped.startswith("="):
            # The CRC24 checksum line, e.g. "=63js".
            checksum = stripped
            continue
        payload_lines.append(stripped)

    return strict_b64decode("".join(payload_lines)), checksum


def crc24(data):
    """The OpenPGP radix-64 CRC24 (RFC 4880 §6.1).

    Initial value 0xB704CE, polynomial 0x1864CFB, no bit reflection and no
    final XOR, computed over the *decoded* (binary) armour body.
    """
    crc = 0xB704CE
    for byte in data:
        crc ^= byte << 16
        for _ in range(8):
            crc <<= 1
            if crc & 0x1000000:
                crc ^= 0x1864CFB
    return crc & 0xFFFFFF


def crc24_armor_line(blob):
    """The `=XXXX` armour checksum line for a decoded armour body."""
    return "=" + base64.b64encode(crc24(blob).to_bytes(3, "big")).decode("ascii")


def pgp_primary_fingerprints(blob):
    """Fingerprints every primary public-key packet in an OpenPGP key blob.

    Handles both packet-header formats, because vendors publish both (NetBird's
    key is old-format, `0x99` + two-byte length).
    """
    fingerprints = []
    i = 0
    while i < len(blob):
        b0 = blob[i]
        if (b0 & 0x80) == 0:
            break
        i += 1
        if (b0 & 0xc0) == 0xc0:  # new format
            tag = b0 & 0x3F
            l1 = blob[i]
            i += 1
            if l1 < 192:
                length = l1
            elif l1 < 224:
                length = ((l1 - 192) << 8) + blob[i] + 192
                i += 1
            elif l1 == 255:
                length = int.from_bytes(blob[i:i + 4], "big")
                i += 4
            else:
                break  # partial length: stop rather than guess
        else:  # old format
            tag = (b0 >> 2) & 0x0F
            length_type = b0 & 0x03
            if length_type == 0:
                length = blob[i]
                i += 1
            elif length_type == 1:
                length = int.from_bytes(blob[i:i + 2], "big")
                i += 2
            elif length_type == 2:
                length = int.from_bytes(blob[i:i + 4], "big")
                i += 4
            else:
                break  # indeterminate length: stop rather than guess

        packet = blob[i:i + length]
        i += length

        if tag == 6 and packet[:1] == b"\x04":
            fingerprints.append(
                hashlib.sha1(b"\x99" + len(packet).to_bytes(2, "big") + packet).hexdigest().upper()
            )

    return fingerprints
