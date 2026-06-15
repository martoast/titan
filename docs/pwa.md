# Titan — Progressive Web App (PWA)

Titan is installable as a standalone app on phones, tablets, and desktops.

## Install ("Add to Home Screen")

**iOS / iPadOS (Safari)**
1. Open Titan in Safari.
2. Tap the **Share** button.
3. Tap **Add to Home Screen** → **Add**.
4. Launch Titan from the new home-screen icon — it opens full-screen with the dark theme.

**Android (Chrome)**
1. Open Titan in Chrome.
2. Tap the **⋮** menu (or the install prompt).
3. Tap **Install app** / **Add to Home Screen**.

**Desktop (Chrome / Edge)**
- Click the **install icon** in the address bar, or **⋮ → Install Titan**.

## What's included

| File | Purpose |
| --- | --- |
| `public/manifest.webmanifest` | App metadata: name, theme/background `#07080a`, `display: standalone`, `start_url: /dashboard`, portrait, health/fitness categories, icons. |
| `public/icons/icon.svg`, `icon-192.svg`, `icon-512.svg` | App icon (indigo→cyan TITAN "T" glyph on a dark rounded square). |
| `public/icons/icon-maskable.svg` | Maskable variant (full-bleed, glyph in the safe zone) for adaptive Android icons. |
| `public/icons/apple-touch-icon.svg` | 180×180 iOS home-screen icon. |
| `public/sw.js` | Service worker — app-shell offline strategy. |
| `public/offline.html` | On-brand "You're offline" fallback page. |

## Offline / caching strategy (`sw.js`)

- **Precache:** offline page, manifest, and icons (installed up front).
- **Navigations:** network-first; falls back to a cached copy, then `offline.html`.
- **Static assets** (`/build/*`, icons, fonts, css/js/images): cache-first.
- **`/api/*` and `/sanctum/*`:** network-only — never cached (no stale authenticated data).
- **Versioning:** bump `CACHE_VERSION` in `sw.js` on deploy; old caches are purged on `activate`.

## Notes

- **HTTPS required.** Service workers only run over HTTPS — **`localhost` is exempt**, so local dev works without TLS.
- **Swapping in real PNG icons later:** the manifest currently uses SVG icons, which is valid and crisp at any size. If you want raster PNGs (e.g. for older Android or richer splash screens), export `192×192` and `512×512` PNGs into `public/icons/`, add them to the `icons` array in `manifest.webmanifest` (alongside or instead of the SVGs), and update the `apple-touch-icon` link in `resources/views/components/titan-layout.blade.php` to a `.png`. No other changes needed.
- After changing the SW or icons, hard-reload (or bump `CACHE_VERSION`) to pick up the new version.
