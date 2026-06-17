# Titan browser tests

Real-browser tests for the things the PHP suite (`php artisan test`) can't see: AJAX chat switching,
the loading overlay, scroll-up pagination, and that pages actually render. They drive a headless
Chrome via the gstack **`browse`** binary and assert front-end behaviour by reading/poking Alpine
state directly.

## Prereqs

- The Titan Sail app running at `http://localhost:8088` (`./vendor/bin/sail up -d`).
- The gstack `browse` binary at `~/.claude/skills/gstack/browse/dist/browse` (override with `BROWSE_BIN`).
- `APP_ENV=local` — the `/dev/login` shortcut (passwordless sign-in for the harness) is **only**
  registered in the local environment and 404s anywhere else.

## Run

```bash
tests/browser/smoke.sh          # full smoke; non-zero exit on any failure; screenshots in /tmp/titan-browse
```

## Harness

`browse.sh` wraps the binary and always resolves the daemon from the Titan git root, so it runs an
**isolated** Chrome session for Titan (independent of any other project's browse daemon).

```bash
tests/browser/browse.sh login [email]      # passwordless sign-in (first user by default) → /coach
tests/browser/browse.sh goto /progress     # navigate to a Titan path
tests/browser/browse.sh seed --messages=44 # seed a long [browse-test] thread → prints its id
tests/browser/browse.sh url | text | screenshot /tmp/x.png | js '<expr>' | snapshot -i
```

## The Alpine trick

The coach page has several `[x-data]` roots; find the chat one by a distinctive method, then read or
poke its state:

```js
var el = [...document.querySelectorAll('[x-data]')]
  .find(e => { try { return 'openChat' in window.Alpine.$data(e) } catch (_) { return false } });
var d = window.Alpine.$data(el);
// d.messages, d.hasMore, d.oldestId, d.activeId, d.loadingChat
// d.openChat(id) · d.loadOlder() · d.loadingChat = true (then reset — it's a real session)
```

## Notes / gotchas

- Blade changed → `sail artisan view:clear`. New arbitrary Tailwind class → `npm run build`.
- `/dev/login` is dev-only and guarded twice (route registration + handler). Never ships to prod.
- `titan:browse-seed` is idempotent — it clears prior `[browse-test]` threads each run.
