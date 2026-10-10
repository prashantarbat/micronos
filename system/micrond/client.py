#!/usr/bin/env python3
"""
MicronOS IPC Client Tool
========================
CLI tool for manual testing and debugging of the micrond Unix Domain Socket.

JSON is parsed and emitted strictly: the JavaScript `NaN` / `Infinity` forms
`json.loads` accepts by default are not valid JSON (RFC 8259), so a response
carrying one is rejected rather than rendered, and nothing this tool sends can
carry one either.
"""

import socket
import struct
import json
import sys

SOCKET_PATH = "/run/micron/micrond.sock"


def _reject_nonstandard_json_constant(token: str):
    """Rejects the `NaN` / `Infinity` / `-Infinity` forms `json.loads` accepts."""
    raise ValueError(f"non-standard JSON constant rejected: {token}")


def strict_json_loads(text: str):
    """`json.loads` with the non-standard NaN/Infinity extensions disabled."""
    return json.loads(text, parse_constant=_reject_nonstandard_json_constant)


def send_rpc(method: str, params: dict = None, req_id: int = 1):
    if params is None:
        params = {}

    # allow_nan=False so the client can never put a non-standard constant on
    # the wire either; a bad param fails loudly here instead of silently.
    payload = json.dumps({
        "jsonrpc": "2.0",
        "id": req_id,
        "method": method,
        "params": params
    }, allow_nan=False).encode("utf-8")

    sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        sock.connect(SOCKET_PATH)
        header = struct.pack("!I", len(payload))
        sock.sendall(header + payload)

        resp_header = sock.recv(4)
        if not resp_header:
            print("Error: No response from daemon.")
            return

        resp_len = struct.unpack("!I", resp_header)[0]
        data = b""
        while len(data) < resp_len:
            chunk = sock.recv(min(resp_len - len(data), 4096))
            if not chunk:
                break
            data += chunk

        try:
            resp = strict_json_loads(data.decode("utf-8"))
        except (ValueError, UnicodeDecodeError) as exc:
            print(f"Error: invalid JSON response from daemon: {exc}")
            return

        print(json.dumps(resp, indent=2, allow_nan=False))
    except Exception as e:
        print(f"Connection failed: {e}")
    finally:
        sock.close()

if __name__ == "__main__":
    method_name = sys.argv[1] if len(sys.argv) > 1 else "system.ping"
    send_rpc(method_name)
