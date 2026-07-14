# BIO AGE PREMIUM — the cinematic animated hero (the screenshot moment)

**Status:** BUILD NOW · **Requested by:** Alex, 2026-07-13
**One line:** The Bio Age page is the most-screenshotted, most-shared surface in the app — make its
hero *cinematic*, Whoop-grade: the age number animates in, a radial dial draws around it, and the
"years younger" lands as the emotional hit. Every primitive already exists; the hero just doesn't use
them yet.

**Current state:** `BioAgePage.hero` is STATIC — a 76pt `Text(titan)` in the brand gradient, a delta
line, a share button. No count-up, no ring, no reveal. That's the whole gap.

**Reuse (already in the design system):** `CountUp` (Components.swift:33 — animates a number to its
value), `MetricRing`/`StatRing` (:53/:80 — a ring that draws via `.trim` + `withAnimation`),
`Theme.Motion`, `Theme.Grad.brand/glow`, `Haptic`. This is assembly, not invention.

---

## The signature move (better than Whoop's plain count-up)
Whoop counts a number up from 0. We do something more emotionally resonant: **start at the user's
CHRONOLOGICAL age and animate DOWN to their Titan Age** while a "years younger" counter ticks *up* and
the dial draws. It literally animates *"you're younger than your age."* For Alex: **30.2 → 22.5**, with
"YEARS YOUNGER" climbing to **7.6**. (If a user is OLDER than their age, it animates up to the Titan
Age and the delta reads "years older" in amber — same choreography, honest either way.)

## The reveal sequence (on page open — orchestrated, ~1.6s total)
1. **Ambient in (0–0.2s):** a soft `Theme.Grad.glow` blooms behind where the dial will sit.
2. **Dial draws + number morphs (0.2–1.4s, synchronized):** a **radial age dial** (a `MetricRing`-style
   arc) draws from empty to its position while the big age number animates from chronological → Titan
   Age. Use tabular/monospaced digits so it doesn't jitter. Ease-out; the ring and number land
   together. A soft **`Haptic` success tap** on settle.
3. **The delta lands (1.4–1.7s):** **"7.6 YEARS YOUNGER"** springs in — scale-from-0.9 + fade, in the
   band accent (mint/brand for younger, amber for older). This is the screenshot beat — make it the
   boldest thing on screen.
4. **Supporting details fade in:** "Your real age is 30.2", the confidence chip, then the breakdown
   waterfall + tips cascade in below (small stagger).
5. **`prefers-reduced-motion`:** skip the choreography — render the final composed state instantly.

## The dial (the centerpiece visual)
- A radial gauge with the age number centered — the share-worthy shape. Band-colored
  (`Theme.Palette.recovery(...)`-style / brand gradient for younger). Subtle glow ring behind it.
- What the arc encodes: keep it meaningful, not decorative — e.g. the arc sweeps to a position on a
  "younger ← → older" scale, or fills proportional to the delta. Pick one and label it so it reads.
- Depth: the number in `Theme.Grad.brand`, a soft outer glow, generous negative space. Dark,
  luminous, cinematic — matches the summary sheets' premium feel.

## Share = the hero, as an image
The Share button should hand off a **rendered image of this hero** (via `ImageRenderer`) — the dial +
number + delta + the Titan mark — not just `shareText`. The whole point is a beautiful thing to post;
make the shared artifact match the on-screen premium.

## Micro-craft
- Monospaced/tabular digits during the count (no width jitter).
- The count eases out (fast then settle), not linear.
- One haptic, on settle — not per-tick.
- Re-trigger the reveal on pull-to-refresh / when the value changes, not on every scroll.

## Acceptance
- [ ] On open, the hero plays the reveal: dial draws + age morphs chronological→Titan, then the delta
      springs in; a single settle haptic; reduced-motion renders final state instantly.
- [ ] A radial age dial with the number centered is the visual centerpiece (band-colored, glow).
- [ ] Share exports a rendered image of the hero (dial + number + delta), not just text.
- [ ] Reuses `CountUp` / the ring components / `Theme.Motion` — no new one-off animation code where a
      primitive exists.
- [ ] Feels as premium as the post-workout/sleep summary sheets. Henry verifies the payload still
      drives it correctly on Alex's real numbers (22.5 / 30.2 / −7.6); Alex judges the animation on a
      build.
