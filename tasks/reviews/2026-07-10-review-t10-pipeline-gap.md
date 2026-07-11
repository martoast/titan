# T10 motion frame (Lever 1) — firmware good, but the decode pipeline is a 3-part chain, and iOS blocks it

**Reviewer:** Henry (server) · **Against:** e0bb5aa (firmware T10 continuous motion frame)

## Firmware side: good

The always-on `motionEMA` banked once per 30 s epoch overnight via a dedicated T10 frame, no new timer,
riding onHRM, ~20 KB/night — exactly Lever 1. Nice. Ships on the **watch reload** (firmware, not a server
deploy — Alex must reload the watch app to start emitting T10).

## But the data goes nowhere yet — T10 needs iOS + server decoders, and iOS has a hard blocker

The dense movement strip needs: **firmware (done) → iOS FrameRouter routes+decodes T10+uploads → server
ingests T10 → SleepDetail.motion_series → v2 timeline.** Two of those four are missing, and the iOS one
has a structural blocker:

### iOS FrameRouter can't route a 2-digit frame tag (blocker)
`FrameRouter.handle()` (`ios/Titan/Sources/BandSync/FrameRouter.swift:112-113`) hardcodes a **3-char**
prefix:
```swift
let payload = String(s.dropFirst(3))   // strip "Tn:"
switch s.prefix(3) { case "T1:": … }
```
For `"T10:…"`, `s.prefix(3)` == `"T10"` (no colon) and `dropFirst(3)` leaves `":…"` (stray colon).
- **Good:** `"T10"` ≠ `"T1:"`, so a T10 frame is **safely ignored, never mis-decoded as T1** — no
  corruption. The agent's T1/T10 collision caveat is satisfied on iOS.
- **Bad:** you **cannot** add `case "T10:"` and have it work — it's 4 chars, `prefix(3)` never yields it.
  T10 is un-routable until the dispatch is generalized. Fix: split on the first `:` for a variable-length
  frame tag (`let i = s.firstIndex(of: ":")` → tag = before, payload = after), then `switch tag`.
  Audit the SERVER frame parser for the same 3-char / `startsWith("T1")` assumption (the agent flagged
  this server-side — confirm it splits on the colon too).

### Then the rest of the chain
- iOS: add `FrameDecoder.decodeT10` (12-byte LE: ver=10, motion `uint16` = motionEMA×1000, 64-bit
  unix-ms ts) and upload it as a motion-bearing ingestion (its own kind, or fold into the sleep window
  channel) so it reaches the server.
- Server: T10 ingestion path → persist per-epoch motion → `SleepDetail.motion_series` (per
  SLEEP_TIMELINE_V2 §3) prefers this continuous channel, falls back to the sparse burst proxy for old
  nights.

## Net
Firmware Lever 1 is correct and safe to ship. But until the FrameRouter is de-hardcoded and the
decode/ingest chain lands, the v2 movement strip still runs on the sparse ~17% burst proxy — the dense
data is being logged on-wrist and dropped on sync. Priority: generalize the FrameRouter prefix split
(it's the gate for T10 AND any future 2-digit frame), then decodeT10 → ingest → motion_series.

*Also noted for the record: this hardcoded-3-char assumption means T7/T8/T9 are the ceiling for
single-digit tags — worth fixing once, properly, so the next frame type isn't another silent drop.*

— Henry
