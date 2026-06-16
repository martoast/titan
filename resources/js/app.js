

import Alpine from 'alpinejs';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

// Titan bridge frame decoders + workout assembler → window.TitanBridge (used by the device bridge).
import './bridge-decode.js';

// --- Markdown rendering for the coach chat (full GFM, safely sanitised before x-html) ---
marked.setOptions({ gfm: true, breaks: true });
// Open links in a new tab; never leak the referrer.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.getAttribute('href')) {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }
    if (node.tagName === 'IMG') {
        node.setAttribute('loading', 'lazy');
    }
});
const purify = (md) => DOMPurify.sanitize(marked.parse(String(md ?? ''), { async: false }), {
    ADD_ATTR: ['target', 'rel', 'loading'],
});
// Pull out reasoning-model <think>…</think> blocks → a collapsed "Thinking" disclosure, so the
// actual answer leads and the reasoning is one tap away (idea borrowed from odysseus).
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
        html += `<details class="think"><summary>Thinking</summary><div class="think-body">${purify(b)}</div></details>`;
    }
    return html + purify(body);
};

// --- Post-render polish: code-copy buttons + tap-to-zoom images (run after x-html paints) ---
function openLightbox(src, alt) {
    const o = document.createElement('div');
    o.className = 'lightbox';
    const img = document.createElement('img');
    img.src = src; img.alt = alt || '';
    o.appendChild(img);
    o.addEventListener('click', () => o.remove());
    document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { o.remove(); document.removeEventListener('keydown', esc); } });
    document.body.appendChild(o);
}
window.coachEnhance = (root) => {
    if (!root) return;
    root.querySelectorAll('pre:not([data-enh])').forEach((pre) => {
        pre.setAttribute('data-enh', '1');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'code-copy';
        btn.textContent = 'Copy';
        btn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(pre.querySelector('code')?.innerText ?? pre.innerText);
                btn.textContent = 'Copied';
                setTimeout(() => { btn.textContent = 'Copy'; }, 1400);
            } catch (_) { /* clipboard blocked — ignore */ }
        });
        pre.appendChild(btn);
    });
    root.querySelectorAll('.coach-prose img:not([data-lb])').forEach((img) => {
        img.setAttribute('data-lb', '1');
        img.addEventListener('click', () => openLightbox(img.src, img.alt));
    });
};

window.Alpine = Alpine;

Alpine.start();
