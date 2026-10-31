#!/bin/sh
# ==============================================================================
# Micron OS — NumLock Enforcement Script
# ==============================================================================
# Ensures NumLock is always active across all virtual TTY consoles and logins
# ==============================================================================

if command -v setleds >/dev/null 2>&1; then
    setleds -D +num 2>/dev/null || true
    if [ "$(id -u)" -eq 0 ]; then
        for tty in /dev/tty[1-6] /dev/tty0 /dev/console; do
            if [ -e "$tty" ] && [ -r "$tty" ] && [ -w "$tty" ]; then
                { setleds -D +num < "$tty"; } 2>/dev/null || true
            fi
        done
    fi
fi
