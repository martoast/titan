# Titan — Mobile-First Polish Conventions (read first)

Titan is used **99% on mobile**. Every page must look and feel native on a 390px-wide
phone first, then scale up. The app SHELL is already redesigned (do NOT touch it):
`resources/views/components/titan-layout.blade.php` and `resources/css/app.css` provide the
dark canvas, compact sticky header, **bottom tab bar**, fonts, and tokens.

**Reference page (match this quality):** `resources/views/meals/index.blade.php`.

## Design tokens (already loaded by the shell)
- **Fonts:** body = Manrope (default). Use `font-display` (Archivo) for page section titles
  and ALL big metric numbers. Use `nums` for any figure/stat (tabular alignment).
- **Surfaces:** cards = `rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5`.
- **Accent:** signature gradient `from-indigo-500 to-cyan-400`. Good/success = emerald.
  Warning/high = amber/rose. Text = `text-gray-100` (primary), `text-gray-400` (secondary),
  `text-gray-500` (muted). Uppercase micro-labels: `text-[11px] uppercase tracking-wide text-gray-500`.

## The rules (apply to every page you own)
1. **Headers:** the page title is ALREADY in the top bar (via `<x-titan-layout title="...">`).
   Don't repeat a big title row. If a page needs an action button, make it **full-width on
   mobile** (`w-full md:w-auto`) or an icon button — never a text button crammed next to other
   controls in a row that overflows. Nav/segment controls go full-width (`flex-1`).
2. **No horizontal overflow, ever.** Use `min-w-0`, `truncate`, `flex-wrap`, or a
   `.no-scrollbar` horizontal scroller for chip/tab rows. Test mentally at 360px.
3. **Stats:** stack label ABOVE value; never inline `label  value` in a narrow grid cell.
   Pattern:
   ```html
   <div class="text-[11px] uppercase tracking-wide text-gray-500">Protein</div>
   <div class="font-display text-xl font-bold nums">190<span class="text-gray-500 text-sm font-normal">/210g</span></div>
   ```
4. **Grids:** default 1 col, expand up: `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`. Short
   stat tiles may be `grid-cols-2 sm:grid-cols-3/4`. Gap `gap-3 md:gap-4`.
5. **Forms:** inputs `w-full`, `text-base` (≥16px so iOS doesn't zoom), min height `h-11`,
   `rounded-xl bg-gray-950 border border-white/10 px-3`. Stack fields on mobile, grid on `sm:`.
   Buttons: primary `w-full md:w-auto h-12 rounded-xl font-semibold`, gradient or indigo.
6. **Tap targets:** icon buttons ≥ `h-10 w-10`; primary actions `h-12`. Use `active:` states
   (e.g. `active:bg-white/10`) for press feedback, not only `hover:`.
7. **Charts (Chart.js):** wrap the `<canvas>` in a fixed-height `div` (`h-40 md:h-56`) and set
   `responsive:true, maintainAspectRatio:false`. Canvas `w-full`. Keep legends minimal on mobile.
8. **Spacing:** vertical rhythm `space-y-4 md:space-y-5`. Cards `p-4 md:p-5`. Don't over-pad on
   mobile — screen space is precious.
9. **Modals/overlays:** prefer bottom sheets (slide up from bottom) over centered modals on
   mobile; respect safe area with `pb-safe`.
10. The shell already reserves bottom space for the tab bar (`main` has `pb-28`). Don't add your
    own fixed bottom bars that would collide; if you must (e.g. a sticky "save" CTA), offset it
    above the nav with `bottom-[5rem]` or keep it inline.

## Do / Don't
- DO keep it **simple and calm** — generous spacing, clear hierarchy, big readable numbers.
- DO use `font-display` for the one or two key numbers per card; keep body text Manrope.
- DON'T add new fonts, colors outside the palette, or touch the shell/app.css/controllers.
- DON'T run `npm run build`, migrate, or browser-test — the orchestrator rebuilds + tests on a
  390px viewport and reviews each page. Edit Blade views only.

## Done =
Every page you own renders cleanly at 360–390px wide: no overflow, no cramped label/value pairs,
full-width primary actions, stacked stats, responsive charts, comfortable tap targets — matching
the polish of `meals/index.blade.php`. Return a short list of files changed + notes.
