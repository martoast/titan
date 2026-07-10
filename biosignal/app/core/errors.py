"""Shared error types for the biosignal core.

`DataFaultError` marks a GENUINE bad-payload — input the code deliberately validates and rejects (a
malformed request that will fail identically on every retry). Routers map ONLY this to HTTP 422, which the
Laravel caller treats as DETERMINISTIC (it counts toward the seal's attempt cap).

Everything else — a KeyError/IndexError/TypeError/ValueError from numpy internals, a neurokit signature
change, any unhandled bug — is the signature of a CODE REGRESSION / bad deploy, and must surface as 500,
which the caller treats as TRANSIENT (retry until the deploy is rolled back). Mapping those to 422 would let
a bad deploy destroy nights fleet-wide within the cap window. So NEVER catch bare builtin exceptions and turn
them into 422 — raise DataFaultError explicitly, only where you actually validate input.
"""


class DataFaultError(ValueError):
    """A deliberately-rejected malformed payload → HTTP 422 (deterministic). Subclasses ValueError so existing
    `except ValueError` validators (e.g. pydantic field validators) keep treating it as a validation error."""
