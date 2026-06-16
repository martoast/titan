# Chat Interface Cookbook — a portable recipe for a beautiful, mobile-first AI chat

> **Purpose.** This is a self-contained spec another AI can follow to build a chat UI
> as polished as Titan's coach in a *different* app. It documents the patterns, the exact
> code, the *why* behind each decision, and how to adapt them to other stacks
> (React/Vue/Svelte/vanilla). Nothing here is Titan-specific except where noted.
>
> Reference implementation: Titan coach chat —
> `resources/js/app.js`, `resources/css/app.css` (`.coach-prose` block),
> `resources/views/coach/index.blade.php`. Built with Alpine.js + Tailwind + `marked` + `DOMPurify`.

---

## TL;DR — the 6 things that make it good

1. **Safe, full-GFM markdown** — render with `marked`, then **always** sanitize with `DOMPurify` before injecting as HTML. Never hand-roll a regex renderer; never inject unsanitized model output.
2. **A dedicated prose stylesheet** (`.coach-prose`) — the model returns headings/lists/tables/code/images; style them once, on the dark theme, so every reply looks designed.
3. **Code-copy buttons** — one tap to grab any code block. Hover-reveal on desktop, always-visible on touch.
4. **Tap-to-zoom image lightbox** — chat images open full-screen (tap / Esc to close), safe-area aware.
5. **Collapsible "thinking"** — fold `<think>…</think>` reasoning into a disclosure so the answer leads. No-op for non-reasoning models; future-proof.
6. **Smart autoscroll** — stick to the bottom while the user is there; if they've scrolled up to read history, *don't* yank them down — show a "jump to latest" button instead.

Plus the structural rule: **post-render enhancement is idempotent** (guarded by a `data-*` flag) so re-running it never double-binds.

---

## 1. Markdown rendering — safe by construction

The single most important rule: **model output is untrusted**. It can contain `<script>`,
`onerror=`, `javascript:` URLs, etc. Parse markdown → sanitize → only then inject.

```js
import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions({ gfm: true, breaks: true }); // breaks:true → single \n becomes <br> (chat feels right)

// Harden links + lazy-load images at the sanitizer layer, so it applies to ALL rendered HTML.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.getAttribute('href')) {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer'); // no referrer leak, no tabnabbing
    }
    if (node.tagName === 'IMG') {
        node.setAttribute('loading', 'lazy');
    }
});

const purify = (md) => DOMPurify.sanitize(
    marked.parse(String(md ?? ''), { async: false }),
    { ADD_ATTR: ['target', 'rel', 'loading'] } // allow the attrs the hook adds
);
```

**Why this order?** `marked` turns markdown into HTML; that HTML is *unsafe*. `DOMPurify`
strips anything dangerous (scripts, event handlers, `javascript:` hrefs) while keeping the
formatting. Doing it the other way (sanitize text, then parse) doesn't work — parsing
re-introduces HTML.

**Why a hook instead of post-processing the DOM?** The hook runs inside the sanitizer for
*every* node, including images/links the model embeds, with no extra DOM walk and no
race with the framework's render.

### Collapsible "thinking" blocks

Reasoning models emit `<think>…</think>`. Pull them out so the *answer* is what the user
sees first; the reasoning is one tap away. Harmless no-op when there are no such tags.

```js
function splitThinking(text) {
    const blocks = [];
    const body = String(text ?? '').replace(/<think(?:ing)?>([\s\S]*?)<\/think(?:ing)?>/gi, (_, c) => {
        const t = c.trim();
        if (t) blocks.push(t);
        return '';
    });
    return { blocks, body };
}

window.renderMarkdown = (text) => {
    const { blocks, body } = splitThinking(text);
    let html = '';
    for (const b of blocks) {
        // The <details>/<summary> wrapper is OUR static HTML; only the model's content is purified.
        html += `<details class="think"><summary>Thinking</summary><div class="think-body">${purify(b)}</div></details>`;
    }
    return html + purify(body);
};
```

> **Security note:** it's safe to concatenate a static `<details>` wrapper around `purify(b)`
> because the wrapper is a literal string we control — the only model-derived part goes
> through `purify()`. Never interpolate model text *outside* a `purify()` call.

---

## 2. The prose stylesheet (`.coach-prose`)

A chat reply is a mini-document. Style it once and every message looks intentional.
Key decisions (full CSS in `resources/css/app.css`):

- **Tables and `<pre>` scroll horizontally** (`display:block; overflow-x:auto; -webkit-overflow-scrolling:touch`) instead of squashing or overflowing the viewport on a phone. This is the #1 mobile markdown bug.
- **`word-break: break-word`** on the container so long URLs/tokens don't blow out the bubble width.
- **Inline `code` vs block `pre`** styled distinctly (inline = subtle pill; block = bordered panel).
- **First/last child margins zeroed** (`.coach-prose > :first-child { margin-top:0 }`) so the bubble padding stays even.
- **List markers tinted** with the brand accent (`li::marker { color: … }`) — a cheap touch that reads as "designed."
- **Images** get `max-width:100%; height:auto; border-radius; cursor:zoom-in` — responsive and obviously tappable.

Adapt the colors to your theme; keep the *structural* rules (scroll-on-overflow, break-word, zeroed edge margins) verbatim — they're what make it robust.

```css
.coach-prose { font-size: 0.9375rem; line-height: 1.65; color: #e5e7eb; word-break: break-word; }
.coach-prose > :first-child { margin-top: 0; }
.coach-prose > :last-child  { margin-bottom: 0; }
.coach-prose pre   { overflow-x: auto; -webkit-overflow-scrolling: touch; position: relative; }
.coach-prose table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.coach-prose img   { max-width: 100%; height: auto; border-radius: 0.85rem; cursor: zoom-in; display: block; }
/* …headings, lists, blockquote, inline code, th/td — see app.css for the full set */
```

---

## 3. Post-render enhancement (code-copy + lightbox)

After the framework paints the HTML, walk the new nodes once and attach interactivity.
**Idempotency is mandatory** — guard with a `data-*` flag so re-running never double-binds.

```js
function openLightbox(src, alt) {
    const o = document.createElement('div');
    o.className = 'lightbox';
    const img = document.createElement('img');
    img.src = src; img.alt = alt || '';
    o.appendChild(img);
    o.addEventListener('click', () => o.remove());
    document.addEventListener('keydown', function esc(e) {
        if (e.key === 'Escape') { o.remove(); document.removeEventListener('keydown', esc); }
    });
    document.body.appendChild(o);
}

window.coachEnhance = (root) => {
    if (!root) return;
    // Code-copy button on every <pre> not yet enhanced.
    root.querySelectorAll('pre:not([data-enh])').forEach((pre) => {
        pre.setAttribute('data-enh', '1');           // ← idempotency guard
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'code-copy';
        btn.textContent = 'Copy';
        btn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(pre.querySelector('code')?.innerText ?? pre.innerText);
                btn.textContent = 'Copied';
                setTimeout(() => { btn.textContent = 'Copy'; }, 1400);
            } catch (_) { /* clipboard blocked (insecure context) — fail silently */ }
        });
        pre.appendChild(btn);
    });
    // Tap-to-zoom on every chat image not yet wired.
    root.querySelectorAll('.coach-prose img:not([data-lb])').forEach((img) => {
        img.setAttribute('data-lb', '1');
        img.addEventListener('click', () => openLightbox(img.src, img.alt));
    });
};
```

CSS that makes these work (the non-obvious bits):

```css
/* Copy button: hover-reveal on desktop, always-on for touch (no hover exists). */
.coach-prose pre { position: relative; }
.code-copy { position: absolute; top: .5rem; right: .5rem; opacity: 0; transition: opacity .15s; /* …*/ }
.coach-prose pre:hover .code-copy, .code-copy:focus { opacity: 1; }
@media (pointer: coarse) { .code-copy { opacity: .8; } }   /* ← key: touch devices can't hover */

/* Lightbox: full-screen, safe-area aware, tap/Esc to close. */
.lightbox { position: fixed; inset: 0; z-index: 80; display: grid; place-items: center;
    background: rgba(0,0,0,.88); cursor: zoom-out;
    padding: env(safe-area-inset-top) 1rem env(safe-area-inset-bottom); }
.lightbox img { max-width: 100%; max-height: 100%; }

/* Collapsible thinking. */
.coach-prose details.think > summary { cursor: pointer; list-style: none; /* …*/ }
.coach-prose details.think > summary::-webkit-details-marker { display: none; }
.coach-prose details.think > summary::before        { content: '▸ '; }
.coach-prose details.think[open] > summary::before  { content: '▾ '; }
```

> **`@media (pointer: coarse)`** is the workhorse for mobile correctness — it's how you say
> "this device has no hover, so reveal the affordance permanently." Use it for the copy
> button here, and (see below) to force 16px inputs.

---

## 4. Smart autoscroll + jump-to-latest

The behavior users actually want: *stay pinned to the newest message while I'm reading it,
but if I scrolled up to re-read something, don't rip me back down when a reply arrives —
give me a button instead.* Framework-agnostic logic (shown in Alpine, trivially portable):

```js
nearBottom() {                                   // within 120px of the bottom = "following along"
    const el = this.$refs.scroll;
    return el ? (el.scrollHeight - el.scrollTop - el.clientHeight < 120) : true;
},
scrollDown() {
    const el = this.$refs.scroll;
    if (el) el.scrollTop = el.scrollHeight;
    this.showJump = false;
},
init() {
    this.$nextTick(() => { this.scrollDown(); this.enhance(); }); // start pinned + enhance history
    this.$refs.scroll?.addEventListener('scroll',
        () => { this.showJump = !this.nearBottom(); }, { passive: true });
},

// In the send() handler, AFTER pushing the assistant reply:
const wasNear = this.nearBottom();               // capture BEFORE the DOM grows
this.messages.push({ role: 'assistant', content: reply });
this.$nextTick(() => {
    this.enhance();                              // attach copy/lightbox to the new message
    if (wasNear) this.scrollDown();              // they were following → keep them pinned
    else this.showJump = true;                   // they were reading history → offer the button
});
```

The "jump to latest" button is just `x-show="showJump"` (or `v-if`/`{showJump && …}`) calling `scrollDown()`.

**Order of operations matters:** measure `nearBottom()` *before* you append the reply (appending
changes `scrollHeight`), and run `enhance()` + scroll inside the post-render tick
(`$nextTick`/`requestAnimationFrame`/`useEffect`) so the nodes exist.

---

## 5. Conversation persistence (nice-to-have)

When the first message of a *new* thread comes back with an id, point the send URL and the
browser URL at it so a refresh keeps the thread — without a full navigation:

```js
if (data.conversation_id && !this.sendUrl.match(/\/coach\/\d+\/send/)) {
    this.sendUrl = '/coach/' + data.conversation_id + '/send';
    history.replaceState(null, '', '/coach?c=' + data.conversation_id);
}
```

---

## 6. Mobile-first essentials (don't skip these)

These three CSS rules prevent the most common "feels broken on iPhone" bugs:

```css
/* iOS Safari auto-zooms when you focus an input with <16px text. Force 16px on touch only. */
@media (pointer: coarse) {
    input:not([type=checkbox]):not([type=radio]):not([type=range]), select, textarea { font-size: 16px; }
}
/* Momentum scrolling + hidden bars for the message list and any chip rows. */
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
.no-scrollbar::-webkit-scrollbar { display: none; }
/* Safe-area padding (use with <meta name=viewport content="…, viewport-fit=cover">). */
.pb-safe { padding-bottom: env(safe-area-inset-bottom); }
```

Also: give the scroll container `overscroll-behavior: contain` so pull-to-refresh / scroll
chaining doesn't escape the chat, and put the composer in a sticky footer with `pb-safe`.

---

## 7. Optional next level — streaming

Everything above works with a single request/response (await the full reply, then render).
The biggest remaining upgrade is **token streaming over SSE** so text appears as it's
generated ("feels alive"), instead of several seconds of dead air. Sketch:

- **Server:** stream the model's tokens to an `text/event-stream` response (`data: {delta}\n\n`).
- **Client:** read the response body with `ReadableStream`/`EventSource`, append deltas to the
  in-progress assistant message, and call `renderMarkdown()` on each flush (debounce to ~animation frame).
- **Re-render safety:** because `renderMarkdown` + `coachEnhance` are idempotent (the `data-*`
  guards), you can re-render the growing message every frame without double-binding buttons.
- Keep `wasNear` autoscroll logic — re-evaluate it each flush so streaming keeps the user pinned.

Markdown-while-streaming caveat: partial markdown (an unclosed ``` fence) renders oddly
mid-stream. Acceptable in practice; if it bothers you, render the streaming text as plain
`<pre>` and swap to full `renderMarkdown()` on completion.

---

## Adapting to another stack

| Titan uses | Swap for | Notes |
|---|---|---|
| Alpine `x-data`/`x-html` | React state + `dangerouslySetInnerHTML`, Vue `v-html`, Svelte `{@html}` | The render fn is framework-agnostic — it returns a sanitized HTML string. |
| `$nextTick` | `useEffect`/`requestAnimationFrame`, Vue `nextTick`, Svelte `tick()` | Run `enhance()` + scroll *after* paint. |
| `$refs.scroll` | a ref to the scroll container | All scroll math is plain DOM. |
| `marked` + `DOMPurify` | keep them | Both are tiny, framework-free, and battle-tested. Do **not** substitute a regex renderer. |
| Tailwind | any CSS | `.coach-prose` is plain CSS; copy it as-is. |

**Hard rules to carry over regardless of stack:** (1) always sanitize after parsing;
(2) make post-render enhancement idempotent with `data-*` guards; (3) measure `nearBottom()`
before appending; (4) use `@media (pointer: coarse)` for hover-less affordances and 16px inputs.

---

*Generated from Titan's coach chat implementation. Files of record:*
*`resources/js/app.js`, `resources/css/app.css` (`.coach-prose` block), `resources/views/coach/index.blade.php`.*
