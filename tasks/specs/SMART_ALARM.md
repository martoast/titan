# SMART ALARM — wake in a light-sleep window, on-wrist, silent

**Spec for the dev agent · from Alex + Henry · 2026-07-11 · Whoop-parity feature**
**Status: BUILD. Small, delightful, mostly firmware.**

## The idea

Whoop's haptic alarm vibrates you awake in the ~30 min BEFORE your target time, choosing a moment you're
in light sleep (or already stirring) so you wake gentle instead of ripped out of deep sleep. Silent,
partner-friendly, feels like magic. **Our band already buzzes and already tracks sleep state — this is
wiring, not new hardware.**

## What exists

- **Band can buzz** — `Bangle.buzz` is used for find-my-band / cue acks; the `buzz_band` command path
  exists server→band.
- **On-device sleep state** — the firmware duty-cycles overnight, tracks motion quiet (`quietSince`,
  `AUTO_MOTION_LO`), runs HRV bursts, and the user marks bedtime/wake (T9, `titan.app.js:181-185`). It
  knows, on-wrist, roughly how still/active the wrist is right now — enough for a light-sleep proxy.
- **Sleep faces + sleep duty CFG** — `titan.app.js:139-152`.

## The build (firmware-led)

### 1 · A wake-window alarm on the watch
- New CFG + state: a **target wake time** and a **window** (default 30 min before). Set from the app
  (a "Smart Alarm" setting) and synced to the band as a small command (reuse the command/settings channel
  that already carries run-distance/priming to the watch).
- During the window, the firmware watches its live motion/actigraphy: when it detects the user is in a
  **light-sleep or stirring** state (motion above the deep-still floor but not awake — the same actigraphy
  signal the sleep staging leans on), it **buzzes a gentle escalating pattern** to wake them. If the
  window elapses with no light-sleep moment detected, buzz AT the target time as a hard backstop (never
  let them oversleep).
- Wake-on-button dismiss; a second "snooze" pattern optional. On dismiss, emit the T9 wake marker (it
  already does this on "mark awake") so the night seals at the real wake instant.
- **Battery**: the actigraphy is already running for sleep; this adds only a short watchful window +
  a buzz. Negligible — but keep the window bounded and stand down immediately on dismiss.

### 2 · The setting (app + watch)
- iOS/web: a Smart Alarm control on the sleep screen — target time, on/off, window length, "wake me in
  light sleep vs at exact time." Persist server-side; push to the band on connect/sync.
- Show it on the watch Sleep face (next alarm time) so it's trustworthy without the phone.
- Optional coach hook: "want me to set a smart alarm for 6:30?" → sets it via a `set_alarm` tool.

### 3 · Honesty & safety
- If the band is offline/dead at wake time, the app should fall back to a phone-side local notification
  at the target time (never silently fail to wake someone). Make the fallback explicit in the UI.
- The light-sleep detection is a PROXY (actigraphy, not full staging on-device) — that's fine for an
  alarm (Whoop's is too); the backstop-at-target guarantees it never fails dangerously.

## Acceptance
- Set a target wake; the band buzzes within the window at a stirring/light-sleep moment, or at the target
  as backstop; dismiss emits the T9 wake marker and the night seals at that instant.
- Band-offline path falls back to a phone notification at target time.
- Setting round-trips app ↔ server ↔ watch and shows on the Sleep face.

## Notes
- This pairs naturally with the sleep timeline work — the morning summary can show "woke you at 6:26,
  light sleep" on the hypnogram.
- Keep the buzz pattern gentle-escalating, not a jolt — the whole point is a soft landing.

*The band's on your wrist all night and it can already feel you moving. Let it wake you at the right
moment instead of an arbitrary one.*
