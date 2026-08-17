# AUDIT · Tester B's 26 sleep logs — one guard collision, one truncated night, two missing plausibility bounds

**Author:** Henry (server) · 2026-08-17 · at Alex's request ("analyse her nights, fix any issues on our
side, make sure the data we show is sound")

Two of these were actively wrong on her screen this morning. Both are repaired on prod; the ingest half is
fixed forward in `b5961d9` + `672735e`. The rest is filed, not fixed.

---

## 1. FIXED · The firmware's clock floor and the server's cancelled each other out

Tester B's band died at 05:53 (Tijuana) on 08-16, rebooted, and came back at 00:19 on 08-17 running **227.7 days
slow**. It streamed her whole night stamped `2026-01-01`. That night sealed as sleep_log #200 — a confident,
uncaveated *"6.9h, 5 REM periods, fell asleep within 8 minutes"* dated seven months ago — while her real
night was simply **absent from the app**.

Neither guard was broken. They were built for each other and cancelled:

| | value | behaviour |
|---|---|---|
| firmware `CFG.CLOCK_FLOOR` | `1767225600` = 2026-01-01 | floors the RTC on a dead-battery boot so frames are "at least plausible" |
| server `CLOCK_FLOOR` | `2020-01-01` | re-anchors anything *older* |

The firmware comment says *"C2/GPS corrects to real time on the next sync **and the server re-anchors the
offset**"*, and *"Bump on major re-flashes to keep it recent"* — but every bump moves the bad timestamp
further from the only thing that was catching it. The firmware moved it from a value we caught (1970) to one
we ignored, and the promised re-anchor never ran.

**The replacement invariant is relative, not a date.** A band cannot present data it has no room to hold: the
log ring is 6 × 700 KB and evicts its oldest segment (~16 h of overnight PPG), so a sample staler than
`CLOCK_STALE_MAX_SEC` (30 days) is a broken clock, never a store-and-forward replay. That cannot be defeated
by a future reflash.

Two details worth keeping:

- **The correction is ONE offset per batch, not a per-window re-anchor.** The existing re-anchor hangs each
  window off `now()` — right for a single marker, but it would collapse a 144-window night onto one instant:
  it keeps each window's length and destroys the spacing *between* them, which is the only thing the
  hypnogram is built from.
- **The T5/T10 trend points needed it too** (`672735e`). They carried the band's epoch through with no clock
  guard at all, and they are the *first* thing a rebooted band dumps. A windows-only estimator would have
  returned 0 for exactly the batches that need correcting most — a reconnect dump often has trend points and
  no windows.

**Data repaired.** The offset was measurable and rock-steady — 19,674,443 s of skew for 97 of 128
steady-state windows, ±1 s — so the night was recoverable rather than lost. Minus the 99 s median upload lag
measured on the healthy night before it, the clock error is **19,674,343 s**. Applied to 144 windows, 538 HR
samples and 702 motion samples; the phantom sleep_log and recovery_log deleted; windows reopened to
`processed`. They now cluster as **one session, 2026-08-17 00:19 → 07:26 Tijuana**, which seals on the next
hourly cron after the quiescence gap.

Sanity check on the recovered night, from the trend series alone: HR 82–86 bpm through the afternoon of
08-16, nothing from 18:00 to 00:19 (band off), then 66 → 60 → 57 → 54 → **53** → 54 → 58. A clean nocturnal
descent with the trough at 06:00. The data is good; only the label was wrong.

Backups of every touched row: `~/deploy/data-backups/2026-08-17-vel-clock/`.

## 2. FIXED (as data) · A dead battery is being reported as a short night, with coaching attached

The night the band actually died — 08-16, log #199 — sealed as a **4.8h night** and was believed. Stages were
caveated (`stages_low_confidence = 1`), duration was not. So:

- it was **her entire sleep debt**: balance went 0.2h → **3.3h, "moderate"**, off this one night;
- and the coach said, in as many words: *"only 4.8h asleep — well short of your ~8h need; that's what's
  building your sleep debt. The fix isn't the shape of the night, it's more of it: an earlier bedtime."*

She slept a normal night. Her watch ran out of battery at 05:53. Titan told her to go to bed earlier.

I set `low_confidence = 1` on #199 with a note recording why (debt is now **0.2h, "light"**), because
`low_confidence` is the existing, exact mechanism for "we could not measure this night honestly" and the debt
ledger already documents that intent. That is a **data repair, not a code fix** — the next battery death
does the same thing again.

**The code fix now has a signal it did not have before.** A band only reboots on power loss, and as of
`b5961d9` the ingest *knows* when a band comes back with a broken clock. That retroactively proves the
preceding silence was a battery death rather than a wake — which is precisely the discriminator the seal is
missing, since "windows stop" currently means both. Sketch: when a batch trips the clock correction, mark the
session that was streaming when the band went quiet as truncated, and withhold its DURATION the way stage
doubt is withheld today. I have deliberately not built this — it is a seal change with a product call inside
it (does a truncated night read as unmeasured, or as "at least 4.8h"?), and that is Alex's to make.

Smaller, related: the low-confidence narration for #199 now reads *"Signal was thin overnight… Check your
band fit tonight."* Honest, but the wrong remedy — a battery death should say the battery died.

## 3. OPEN · Plausibility has a ceiling and no floor

`SleepPlausibility` caps deep at 35% and a bout at 90 min, and it earns its keep — it caught #188, #189 and
#199. But:

- **#198 (08-15): 20 min of deep across 7.8h = 4.3%.** Physiologically implausible for a healthy adult
  (~13–23% is normal) and completely unflagged. A floor is as diagnostic as a ceiling.
- **Zero REM over a long sleep is not itself a trigger.** #199 has 0 REM across 4.8h and was only caught
  because it *also* broke the deep ceiling. A night with 0 REM and an unremarkable deep fraction sails
  through.

## 4. CONTEXT · Coverage still reads ~0.99 against ~16% real sampling

Every one of her nights: stored `coverage` 0.98–1.00, actual `hr_series` points per hypnogram epoch
**14–17%**. Known and already documented — the duty-cycle sample-and-hold inflates the bridged number — but
it is the reason the stage model is being fed a grid it never saw in training, and it is upstream of every
plausibility guard above. The durable fix remains the retrain on duty-cycle-matched data.

## 5. OPEN · 1970 debris was never cleaned up

The July episodes left **3,279 HR + 2,784 motion samples** on 1970-01-01..04 for Tester B (plus 23 HR rows for
Alex, and one `0000-00-00`). Same bug, other side of the firmware floor. Inert — every reader is date-bounded
— and unrecoverable, since those rows were never offset-corrected and their true times are gone. Deleting
them is the only sensible end state; I have not, because it is a deletion and nobody is being harmed by it
today.

---

## Her 26 logs, after the repairs

Nothing else in the set is wrong. 21 nights + 3 naps sit in normal ranges; #62 (07-12) and #188/#189 were
already flagged and remain so. #196 (08-11 nap, 78.6% REM over 55 min) is flagged. The gaps in the calendar
— 07-16/17, 07-25, 07-27→08-01, 08-09/10, 08-12/13 — are non-wear, not lost data.

## What I could not verify

- The **C2 time-sync** never corrected the band across seven hours of streaming on a live iOS connection.
  Whether the phone stopped sending it, or the band ignored it, needs Xcode and the band — I can only see
  that the clock stayed wrong. Worth checking before the next reflash, because the server guard is now the
  only thing standing between a dead battery and a misfiled night.
- Her band is **still running on the wrong clock as of this writing**. The fix means tonight's night is
  filed correctly regardless, but the on-watch time display will stay wrong until it re-syncs.
