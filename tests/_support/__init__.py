"""Shared Python helpers for the standalone micrond harnesses.

The harnesses under `tests/` are runnable scripts (`python tests/test_*.py`),
not a package themselves; this directory is a package so they can import the
shared decoding rules in one place instead of each re-implementing them.
"""
