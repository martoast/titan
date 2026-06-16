

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
// Generative UI: the coach can emit a ```titan-card {json}``` block that we render as a
// native component (readiness ring, vitals grid, single stat, sparkline) instead of a table.
function buildRing(score, label, caption) {
    const pct = Math.max(0, Math.min(100, Number(score) || 0));
    const r = 34, c = 2 * Math.PI * r, off = c * (1 - pct / 100);
    const hue = pct >= 67 ? '#34d399' : pct >= 34 ? '#fbbf24' : '#f87171';
    const el = document.createElement('div');
    el.className = 'tcard tcard-ring';
    el.innerHTML =
        `<svg viewBox="0 0 80 80" class="tcard-ring-svg">
            <circle cx="40" cy="40" r="${r}" class="tcard-ring-track"/>
            <circle cx="40" cy="40" r="${r}" class="tcard-ring-arc" stroke="${hue}"
                stroke-dasharray="${c.toFixed(1)}" stroke-dashoffset="${off.toFixed(1)}"/>
        </svg>
        <div class="tcard-ring-body">
            <div class="tcard-ring-score" style="color:${hue}"></div>
            <div class="tcard-ring-label"></div>
            <div class="tcard-ring-caption"></div>
        </div>`;
    el.querySelector('.tcard-ring-score').textContent = Math.round(pct);
    el.querySelector('.tcard-ring-label').textContent = label || '';
    el.querySelector('.tcard-ring-caption').textContent = caption || '';
    if (!caption) el.querySelector('.tcard-ring-caption').remove();
    return el;
}
function buildStats(title, items) {
    const el = document.createElement('div');
    el.className = 'tcard tcard-stats';
    if (title) { const h = document.createElement('div'); h.className = 'tcard-title'; h.textContent = title; el.appendChild(h); }
    const grid = document.createElement('div'); grid.className = 'tcard-grid';
    (items || []).forEach((it) => {
        const cell = document.createElement('div'); cell.className = 'tcard-cell';
        if (it && (it.flag && it.flag !== 'normal' && it.flag !== 'optimal')) cell.classList.add('is-flagged');
        const v = document.createElement('div'); v.className = 'tcard-cell-value';
        v.textContent = (it && it.value != null ? it.value : '–') + (it && it.unit ? ` ${it.unit}` : '');
        const l = document.createElement('div'); l.className = 'tcard-cell-label'; l.textContent = (it && it.label) || '';
        cell.append(v, l);
        grid.appendChild(cell);
    });
    el.appendChild(grid);
    return el;
}
function buildStat(d) {
    const el = document.createElement('div');
    el.className = 'tcard tcard-stat';
    const v = document.createElement('div'); v.className = 'tcard-stat-value';
    v.textContent = (d.value != null ? d.value : '–') + (d.unit ? ` ${d.unit}` : '');
    const l = document.createElement('div'); l.className = 'tcard-stat-label'; l.textContent = d.label || '';
    el.append(v, l);
    if (d.sub) { const s = document.createElement('div'); s.className = 'tcard-stat-sub'; s.textContent = d.sub; el.appendChild(s); }
    return el;
}
function buildSparkline(d) {
    const pts = (d.points || []).map(Number).filter((n) => !Number.isNaN(n));
    const el = document.createElement('div');
    el.className = 'tcard tcard-spark';
    const head = document.createElement('div'); head.className = 'tcard-spark-head';
    const l = document.createElement('span'); l.className = 'tcard-spark-label'; l.textContent = d.label || '';
    const last = document.createElement('span'); last.className = 'tcard-spark-last';
    if (pts.length) last.textContent = pts[pts.length - 1] + (d.unit ? ` ${d.unit}` : '');
    head.append(l, last); el.appendChild(head);
    if (pts.length >= 2) {
        const min = Math.min(...pts), max = Math.max(...pts), span = (max - min) || 1, W = 100, H = 28;
        const path = pts.map((p, i) => `${((i / (pts.length - 1)) * W).toFixed(1)},${(H - ((p - min) / span) * H).toFixed(1)}`).join(' ');
        const up = pts[pts.length - 1] >= pts[0];
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`); svg.setAttribute('class', 'tcard-spark-svg' + (up ? ' is-up' : ' is-down'));
        svg.setAttribute('preserveAspectRatio', 'none');
        const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
        poly.setAttribute('points', path); poly.setAttribute('fill', 'none');
        svg.appendChild(poly); el.appendChild(svg);
    }
    return el;
}
const CYCLE_COLORS = { menstrual: '#fb7185', follicular: '#34d399', fertile: '#22d3ee', ovulation: '#a78bfa', luteal: '#fbbf24' };
function buildCycle(d) {
    const key = (d.phase_key || d.phase || '').toString().toLowerCase();
    const color = CYCLE_COLORS[key] || '#9ca3af';
    const el = document.createElement('div');
    el.className = 'tcard tcard-cycle';
    const dot = document.createElement('div');
    dot.className = 'tcard-cycle-day';
    dot.style.background = color;
    dot.textContent = (d.day != null ? d.day : '–');
    const body = document.createElement('div');
    body.className = 'tcard-cycle-body';
    const ph = document.createElement('div'); ph.className = 'tcard-cycle-phase'; ph.textContent = (d.phase || 'Cycle') + ' phase';
    const sub = document.createElement('div'); sub.className = 'tcard-cycle-sub';
    const bits = [];
    if (d.next_period_days != null) bits.push(d.next_period_days <= 0 ? 'period due' : `period in ${d.next_period_days}d`);
    if (d.fertile) bits.push(`${d.fertile} fertility`);
    sub.textContent = bits.join(' · ');
    body.append(ph, sub);
    el.append(dot, body);
    return el;
}
function renderCards(root) {
    root.querySelectorAll('pre > code.language-titan-card').forEach((code) => {
        const pre = code.parentElement;
        if (!pre || pre.dataset.card) return;
        let d;
        try { d = JSON.parse(code.textContent); } catch (_) { return; } // leave malformed blocks as code
        let card = null;
        if (d.type === 'readiness' || d.type === 'ring') card = buildRing(d.score, d.label, d.caption);
        else if (d.type === 'stats' || d.type === 'vitals') card = buildStats(d.title, d.items);
        else if (d.type === 'stat' || d.type === 'metric') card = buildStat(d);
        else if (d.type === 'sparkline' || d.type === 'trend') card = buildSparkline(d);
        else if (d.type === 'cycle') card = buildCycle(d);
        if (card) { pre.dataset.card = '1'; pre.replaceWith(card); }
    });
}

window.coachEnhance = (root) => {
    if (!root) return;
    renderCards(root);
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
