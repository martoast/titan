

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
// Biological-age "skill card": the Whoop-style reveal — your body's age vs the calendar.
function buildBioage(d) {
    const bio = Number(d.bio_age), chrono = Number(d.chrono_age);
    if (!isFinite(bio) || !isFinite(chrono)) return null;
    const delta = +(chrono - bio).toFixed(1);                 // positive = younger than your age
    const color = delta > 0.5 ? '#34d399' : delta < -0.5 ? '#fb7185' : '#fbbf24';
    const verdict = Math.abs(delta) < 0.5 ? 'On pace with your age'
        : `${Math.abs(delta).toFixed(1)} yr${Math.abs(delta) === 1 ? '' : 's'} ${delta > 0 ? 'younger' : 'older'}`;
    const fmt = n => (n % 1 ? n.toFixed(1) : String(n));

    const el = document.createElement('div'); el.className = 'tcard tcard-bioage';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = 'Biological age'; el.appendChild(head);

    const main = document.createElement('div'); main.className = 'tcard-bioage-main';
    const left = document.createElement('div');
    const num = document.createElement('div'); num.className = 'tcard-bioage-num'; num.style.color = color; num.textContent = fmt(bio);
    const ref = document.createElement('div'); ref.className = 'tcard-bioage-ref'; ref.textContent = `vs ${fmt(chrono)} actual`;
    left.append(num, ref);
    const pill = document.createElement('div'); pill.className = 'tcard-bioage-delta';
    pill.style.color = color; pill.style.background = color + '22'; pill.textContent = verdict;
    main.append(left, pill); el.appendChild(main);

    // Age scale with two markers (you vs your body)
    const lo = Math.min(bio, chrono), hi = Math.max(bio, chrono), pad = Math.max(4, (hi - lo) + 1);
    const min = Math.floor(lo - pad), max = Math.ceil(hi + pad);
    const pos = a => Math.max(2, Math.min(98, ((a - min) / (max - min)) * 100));
    const pB = pos(bio), pC = pos(chrono);
    const scale = document.createElement('div'); scale.className = 'tcard-bioage-scale';
    const track = document.createElement('div'); track.className = 'tcard-bioage-track';
    const fill = document.createElement('div'); fill.className = 'tcard-bioage-fill';
    fill.style.left = Math.min(pB, pC) + '%'; fill.style.width = Math.abs(pB - pC) + '%'; fill.style.background = color;
    const dC = document.createElement('div'); dC.className = 'tcard-bioage-dot'; dC.style.left = pC + '%';
    const dB = document.createElement('div'); dB.className = 'tcard-bioage-dot is-body'; dB.style.left = pB + '%'; dB.style.background = color; dB.style.boxShadow = `0 0 0 4px ${color}33`;
    track.append(fill, dC, dB); scale.appendChild(track);
    const tags = document.createElement('div'); tags.className = 'tcard-bioage-tags';
    const tB = document.createElement('span'); tB.style.left = pB + '%'; tB.style.color = color; tB.textContent = 'body';
    const tY = document.createElement('span'); tY.style.left = pC + '%'; tY.textContent = 'you';
    tags.append(tB, tY); scale.appendChild(tags); el.appendChild(scale);

    if (Array.isArray(d.drivers) && d.drivers.length) {
        const dr = document.createElement('div'); dr.className = 'tcard-bioage-drivers';
        d.drivers.slice(0, 5).forEach(x => {
            const row = document.createElement('div'); row.className = 'tcard-bioage-driver';
            const l = document.createElement('span'); l.textContent = x.label || '';
            const v = document.createElement('span'); v.className = 'tcard-bioage-dval'; v.textContent = x.value || '';
            v.style.color = x.good === false ? '#fb7185' : (x.good ? '#34d399' : '#9ca3af');
            row.append(l, v); dr.appendChild(row);
        });
        el.appendChild(dr);
    }
    if (d.caption) { const c = document.createElement('div'); c.className = 'tcard-bioage-caption'; c.textContent = d.caption; el.appendChild(c); }
    return el;
}

const gradeColor = (v, mid, hi) => v >= hi ? '#34d399' : v >= mid ? '#fbbf24' : '#fb7185';

// Daily check-in: the Recovery · Strain · Sleep loop + the one focus, in one card.
function buildCheckin(d) {
    const el = document.createElement('div'); el.className = 'tcard tcard-checkin';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = d.date || 'Today'; el.appendChild(head);
    const grid = document.createElement('div'); grid.className = 'tcard-checkin-grid';
    const cell = (label, big, sub, color) => {
        const c = document.createElement('div'); c.className = 'tcard-checkin-cell';
        const b = document.createElement('div'); b.className = 'tcard-checkin-big'; b.textContent = big; if (color) b.style.color = color;
        const l = document.createElement('div'); l.className = 'tcard-checkin-lbl'; l.textContent = label;
        const s = document.createElement('div'); s.className = 'tcard-checkin-sub'; s.textContent = sub || '';
        c.append(b, l, s); return c;
    };
    const r = d.recovery || {}, s = d.strain || {}, sl = d.sleep || {};
    grid.append(
        cell('Recovery', r.value != null ? r.value + '%' : '—', r.label || '', r.value != null ? gradeColor(r.value, 34, 67) : null),
        cell('Strain', s.value != null ? (s.value + (s.target ? '/' + s.target : '')) : '—', s.label || '', '#22d3ee'),
        cell('Sleep', sl.pct != null ? sl.pct + '%' : '—', sl.hours != null ? sl.hours + 'h' : '', sl.pct != null ? gradeColor(sl.pct, 75, 90) : null),
    );
    el.appendChild(grid);
    if (d.focus) {
        const f = document.createElement('div'); f.className = 'tcard-checkin-focus';
        const h = document.createElement('div'); h.className = 'tcard-checkin-fh'; h.textContent = d.focus.headline || 'Focus';
        const p = document.createElement('div'); p.className = 'tcard-checkin-fd'; p.textContent = d.focus.detail || '';
        f.append(h, p); el.appendChild(f);
    }
    return el;
}

// Sleep last night: hours + performance, a stacked stages bar, debt + verdict.
function buildSleep(d) {
    const el = document.createElement('div'); el.className = 'tcard tcard-sleep';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = 'Last night'; el.appendChild(head);
    const main = document.createElement('div'); main.className = 'tcard-sleep-main';
    const left = document.createElement('div');
    const num = document.createElement('div'); num.className = 'tcard-sleep-num'; num.textContent = (d.hours != null ? d.hours : '–') + 'h';
    const sub = document.createElement('div'); sub.className = 'tcard-sleep-sub'; sub.textContent = d.status || '';
    left.append(num, sub);
    if (d.performance != null) {
        const pill = document.createElement('div'); pill.className = 'tcard-sleep-perf';
        const c = gradeColor(d.performance, 75, 90); pill.style.color = c; pill.style.background = c + '22';
        pill.textContent = d.performance + '% performance';
        main.append(left, pill);
    } else { main.append(left); }
    el.appendChild(main);
    const stages = d.stages || {};
    const order = [['deep', 'Deep', '#6366f1'], ['rem', 'REM', '#22d3ee'], ['light', 'Light', '#818cf8'], ['awake', 'Awake', '#475569']];
    const total = order.reduce((a, [k]) => a + (Number(stages[k]) || 0), 0);
    if (total > 0) {
        const bar = document.createElement('div'); bar.className = 'tcard-sleep-bar';
        order.forEach(([k, , col]) => { const w = (Number(stages[k]) || 0) / total * 100; if (w > 0) { const seg = document.createElement('div'); seg.style.width = w + '%'; seg.style.background = col; bar.appendChild(seg); } });
        el.appendChild(bar);
        const leg = document.createElement('div'); leg.className = 'tcard-sleep-legend';
        order.forEach(([k, name, col]) => { if (Number(stages[k]) > 0) { const sp = document.createElement('span'); sp.innerHTML = `<i style="background:${col}"></i>${name}`; leg.appendChild(sp); } });
        el.appendChild(leg);
    }
    if (d.debt != null && d.debt > 0.2) { const dn = document.createElement('div'); dn.className = 'tcard-sleep-debt'; dn.textContent = `Carrying ~${d.debt}h of sleep debt.`; el.appendChild(dn); }
    return el;
}

// Strain gauge: a 0–21 track with your value + the recovery-aware target zone.
function buildStrain(d) {
    const max = 21, v = Number(d.value) || 0;
    const el = document.createElement('div'); el.className = 'tcard tcard-strain';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = 'Strain'; el.appendChild(head);
    const main = document.createElement('div'); main.className = 'tcard-strain-main';
    const color = v >= 14 ? '#fb7185' : v >= 8 ? '#fbbf24' : '#22d3ee';
    const num = document.createElement('div'); num.className = 'tcard-strain-num'; num.style.color = color; num.textContent = v.toFixed(1);
    const band = document.createElement('div'); band.className = 'tcard-strain-band'; band.textContent = d.band || '';
    main.append(num, band); el.appendChild(main);
    const track = document.createElement('div'); track.className = 'tcard-strain-track';
    if (d.target_low != null && d.target_high != null) {
        const zone = document.createElement('div'); zone.className = 'tcard-strain-zone';
        zone.style.left = (d.target_low / max * 100) + '%'; zone.style.width = ((d.target_high - d.target_low) / max * 100) + '%';
        track.appendChild(zone);
    }
    const fill = document.createElement('div'); fill.className = 'tcard-strain-fill'; fill.style.width = Math.min(100, v / max * 100) + '%'; fill.style.background = color; track.appendChild(fill);
    el.appendChild(track);
    const scale = document.createElement('div'); scale.className = 'tcard-strain-scale'; scale.innerHTML = '<span>0</span><span>target</span><span>21</span>'; el.appendChild(scale);
    if (d.advice) { const a = document.createElement('div'); a.className = 'tcard-strain-advice'; a.textContent = d.advice; el.appendChild(a); }
    return el;
}

// Bloodwork panel: markers with in-range / flagged dots.
function buildMarkers(d) {
    const el = document.createElement('div'); el.className = 'tcard tcard-markers';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = d.title || 'Bloodwork'; el.appendChild(head);
    const flagColor = f => ({ optimal: '#34d399', normal: '#22d3ee', low: '#fbbf24', high: '#fb7185', borderline: '#fbbf24' })[String(f || '').toLowerCase()] || '#6b7280';
    (d.items || []).slice(0, 12).forEach(it => {
        const row = document.createElement('div'); row.className = 'tcard-markers-row';
        const dot = document.createElement('i'); dot.style.background = flagColor(it.flag);
        const l = document.createElement('span'); l.className = 'tcard-markers-l'; l.textContent = it.label || '';
        const v = document.createElement('span'); v.className = 'tcard-markers-v'; v.textContent = it.value || '';
        row.append(dot, l, v); el.appendChild(row);
    });
    if (d.caption) { const c = document.createElement('div'); c.className = 'tcard-bioage-caption'; c.textContent = d.caption; el.appendChild(c); }
    return el;
}

// Workout started: a live-session banner with today's strain target.
function buildWorkout(d) {
    const el = document.createElement('div'); el.className = 'tcard tcard-workout';
    const top = document.createElement('div'); top.className = 'tcard-workout-top';
    const dot = document.createElement('span'); dot.className = 'tcard-workout-live';
    const t = document.createElement('span'); t.className = 'tcard-workout-title'; t.textContent = d.name || 'Workout';
    top.append(dot, t); el.appendChild(top);
    const sub = document.createElement('div'); sub.className = 'tcard-workout-sub';
    sub.textContent = (d.mode ? d.mode + ' · ' : '') + (d.target_low != null ? `target strain ${d.target_low}–${d.target_high}` : 'call out your sets and I’ll log them');
    el.appendChild(sub);
    return el;
}

// Macros: today's fuel — calories + protein/carbs/fat vs targets. The daily workhorse.
function buildMacros(d) {
    const el = document.createElement('div'); el.className = 'tcard tcard-macros';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = d.title || "Today's fuel"; el.appendChild(head);

    const cal = d.calories || {};
    const cv = Number(cal.value) || 0, ct = Number(cal.target) || 0;
    const calRow = document.createElement('div'); calRow.className = 'tcard-macros-cal';
    const cl = document.createElement('div'); cl.className = 'tcard-macros-callbl'; cl.textContent = 'Calories';
    const cnum = document.createElement('div'); cnum.className = 'tcard-macros-calnum';
    cnum.innerHTML = `<b>${cv.toLocaleString()}</b>${ct ? ` / ${ct.toLocaleString()}` : ''} <span>kcal</span>`;
    const calHead = document.createElement('div'); calHead.className = 'tcard-macros-calhead'; calHead.append(cl, cnum);
    calRow.appendChild(calHead);
    if (ct) {
        const pct = cv / ct * 100;
        const bar = document.createElement('div'); bar.className = 'tcard-macros-track';
        const fill = document.createElement('div'); fill.style.width = Math.min(100, pct) + '%';
        fill.style.background = pct > 105 ? '#fbbf24' : '#22d3ee';
        bar.appendChild(fill); calRow.appendChild(bar);
    }
    el.appendChild(calRow);

    const macros = [['protein', 'Protein', '#34d399'], ['carbs', 'Carbs', '#fbbf24'], ['fat', 'Fat', '#f472b6']];
    const grid = document.createElement('div'); grid.className = 'tcard-macros-grid';
    macros.forEach(([k, name, col]) => {
        const m = d[k] || {}; const v = Number(m.value) || 0, t = Number(m.target) || 0;
        const cell = document.createElement('div'); cell.className = 'tcard-macros-cell';
        const top = document.createElement('div'); top.className = 'tcard-macros-mtop';
        top.innerHTML = `<span>${name}</span><span class="tcard-macros-mval" style="color:${col}">${Math.round(v)}${t ? `/${Math.round(t)}` : ''}g</span>`;
        const bar = document.createElement('div'); bar.className = 'tcard-macros-mtrack';
        const fill = document.createElement('div'); fill.style.width = (t ? Math.min(100, v / t * 100) : 0) + '%'; fill.style.background = col;
        bar.appendChild(fill);
        cell.append(top, bar); grid.appendChild(cell);
    });
    el.appendChild(grid);

    if (d.footer) { const f = document.createElement('div'); f.className = 'tcard-macros-foot'; f.textContent = d.footer; el.appendChild(f); }
    return el;
}

// Athlete score: one fitness number from VO₂max + recovery + strength + activity.
function buildFitness(d) {
    const score = Number(d.score) || 0;
    const color = score >= 70 ? '#34d399' : score >= 40 ? '#fbbf24' : '#fb7185';
    const el = document.createElement('div'); el.className = 'tcard tcard-fitness';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = 'Athlete score'; el.appendChild(head);

    const main = document.createElement('div'); main.className = 'tcard-fitness-main';
    const left = document.createElement('div'); left.className = 'tcard-fitness-scorewrap';
    const num = document.createElement('div'); num.className = 'tcard-fitness-num'; num.style.color = color; num.textContent = score;
    const outof = document.createElement('span'); outof.className = 'tcard-fitness-outof'; outof.textContent = '/100';
    num.appendChild(outof);
    const grade = document.createElement('div'); grade.className = 'tcard-fitness-grade'; grade.style.color = color; grade.textContent = d.grade || '';
    left.append(num, grade);
    main.appendChild(left);
    if (d.vo2max != null) {
        const vo2 = document.createElement('div'); vo2.className = 'tcard-fitness-vo2';
        let html = `<div class="tcard-fitness-vo2num">${d.vo2max}<span> VO₂max</span></div>`;
        if (d.fitness_age != null) html += `<div class="tcard-fitness-vo2sub">fitness age ${d.fitness_age}${d.chrono_age != null ? ` · you're ${d.chrono_age}` : ''}</div>`;
        vo2.innerHTML = html;
        main.appendChild(vo2);
    }
    el.appendChild(main);

    if (Array.isArray(d.pillars) && d.pillars.length) {
        const wrap = document.createElement('div'); wrap.className = 'tcard-fitness-pillars';
        d.pillars.forEach(p => {
            const ps = Number(p.score) || 0;
            const pc = ps >= 70 ? '#34d399' : ps >= 40 ? '#fbbf24' : '#fb7185';
            const row = document.createElement('div'); row.className = 'tcard-fitness-pillar';
            const top = document.createElement('div'); top.className = 'tcard-fitness-ptop';
            top.innerHTML = `<span>${p.label || ''}${p.detail ? ` <i>${p.detail}</i>` : ''}</span><span class="tcard-fitness-pscore">${ps}</span>`;
            const bar = document.createElement('div'); bar.className = 'tcard-fitness-ptrack';
            const fill = document.createElement('div'); fill.style.width = Math.max(2, ps) + '%'; fill.style.background = pc;
            bar.appendChild(fill);
            row.append(top, bar); wrap.appendChild(row);
        });
        el.appendChild(wrap);
    }
    if (d.caption) { const c = document.createElement('div'); c.className = 'tcard-bioage-caption'; c.textContent = d.caption; el.appendChild(c); }
    return el;
}

// Training program (mesocycle): focus muscles, the volume ramp that grows them, and the week's sessions.
function buildProgram(d) {
    const el = document.createElement('div'); el.className = 'tcard tcard-program';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = 'Program'; el.appendChild(head);
    const name = document.createElement('div'); name.className = 'tcard-program-name'; name.textContent = d.name || 'Mesocycle'; el.appendChild(name);

    const meta = document.createElement('div'); meta.className = 'tcard-program-meta';
    (d.focus || []).forEach(f => { const c = document.createElement('span'); c.className = 'tcard-program-chip'; c.textContent = f; meta.appendChild(c); });
    const wk = document.createElement('span'); wk.className = 'tcard-program-wk';
    wk.textContent = `Week ${d.week}/${d.weeks}${d.phase ? ' · ' + d.phase : ''} · ${d.days_per_week}d/wk`;
    meta.appendChild(wk); el.appendChild(meta);

    // Focus volume ramp — the specialization made visible (sets per week, building then deload).
    (d.ramp || []).forEach(r => {
        const sets = (r.sets || []).map(Number);
        if (!sets.length) return;
        const max = Math.max(...sets, 1);
        const row = document.createElement('div'); row.className = 'tcard-program-ramp';
        const lbl = document.createElement('div'); lbl.className = 'tcard-program-rlbl';
        lbl.innerHTML = `<span>${r.muscle}</span><span class="tcard-program-rmax">${Math.max(...sets)} sets/wk</span>`;
        const bars = document.createElement('div'); bars.className = 'tcard-program-bars';
        sets.forEach((s, i) => {
            const last = i === sets.length - 1;
            const b = document.createElement('div'); b.title = `Week ${i + 1}: ${s} sets`;
            const fill = document.createElement('i'); fill.style.height = Math.max(6, s / max * 100) + '%';
            fill.style.background = last ? '#4b5563' : '#a78bfa';   // deload dimmed
            b.appendChild(fill); bars.appendChild(b);
        });
        row.append(lbl, bars); el.appendChild(row);
    });

    if (Array.isArray(d.days) && d.days.length) {
        const wrap = document.createElement('div'); wrap.className = 'tcard-program-days';
        d.days.forEach(day => {
            const row = document.createElement('div'); row.className = 'tcard-program-day';
            const l = document.createElement('div');
            l.innerHTML = `<div class="tcard-program-dn">${day.name}</div><div class="tcard-program-ds">${day.summary || ''}</div>`;
            const s = document.createElement('div'); s.className = 'tcard-program-dsets'; s.textContent = (day.sets || 0) + ' sets';
            row.append(l, s); wrap.appendChild(row);
        });
        el.appendChild(wrap);
    }
    return el;
}

// Autoregulation: read of recovery × performance × adherence → a push/hold/back-off/deload nudge.
function buildAutoreg(d) {
    const vColors = { progress: '#34d399', hold: '#818cf8', adhere: '#fbbf24', back_off: '#fbbf24', deload: '#fb7185' };
    const color = vColors[d.verdict] || '#9ca3af';
    const sColor = s => ({ good: '#34d399', improving: '#34d399', on: '#34d399', moderate: '#fbbf24', flat: '#fbbf24', ahead: '#34d399', poor: '#fb7185', declining: '#fb7185', behind: '#fb7185' })[s] || '#6b7280';
    const el = document.createElement('div'); el.className = 'tcard tcard-autoreg';

    const top = document.createElement('div'); top.className = 'tcard-autoreg-top';
    const pill = document.createElement('span'); pill.className = 'tcard-autoreg-pill'; pill.style.color = color; pill.style.background = color + '22';
    pill.textContent = (d.verdict || '').replace('_', ' ');
    const h = document.createElement('span'); h.className = 'tcard-autoreg-head'; h.textContent = d.headline || '';
    top.append(pill, h); el.appendChild(top);

    if (Array.isArray(d.signals) && d.signals.length) {
        const sig = document.createElement('div'); sig.className = 'tcard-autoreg-signals';
        d.signals.forEach(s => {
            const row = document.createElement('div'); row.className = 'tcard-autoreg-sig';
            const dot = document.createElement('i'); dot.style.background = sColor(s.state);
            const l = document.createElement('span'); l.className = 'tcard-autoreg-sl'; l.textContent = s.label;
            const v = document.createElement('span'); v.className = 'tcard-autoreg-sv'; v.textContent = (s.detail || s.state || '');
            row.append(dot, l, v); sig.appendChild(row);
        });
        el.appendChild(sig);
    }

    const a = d.adjustment || {};
    if (a.volume || a.rir) {
        const adj = document.createElement('div'); adj.className = 'tcard-autoreg-adj';
        if (a.volume) { const x = document.createElement('span'); x.innerHTML = `<b>Volume</b> ${a.volume}`; adj.appendChild(x); }
        if (a.rir) { const x = document.createElement('span'); x.innerHTML = `<b>Effort</b> ${a.rir}`; adj.appendChild(x); }
        el.appendChild(adj);
    }
    if (a.note) { const n = document.createElement('div'); n.className = 'tcard-autoreg-note'; n.textContent = a.note; el.appendChild(n); }
    return el;
}

// Coach memory: what the coach remembers about you, grouped by category.
function buildMemory(d) {
    const el = document.createElement('div'); el.className = 'tcard tcard-memory';
    const head = document.createElement('div'); head.className = 'tcard-title';
    head.textContent = 'What I remember about you'; el.appendChild(head);

    (d.groups || []).forEach(g => {
        const grp = document.createElement('div'); grp.className = 'tcard-memory-group';
        const gh = document.createElement('div'); gh.className = 'tcard-memory-glabel';
        gh.innerHTML = `<span>${g.emoji || '•'}</span> ${g.label || ''}`;
        grp.appendChild(gh);
        const ul = document.createElement('ul'); ul.className = 'tcard-memory-items';
        (g.items || []).forEach(it => { const li = document.createElement('li'); li.textContent = it; ul.appendChild(li); });
        grp.appendChild(ul);
        el.appendChild(grp);
    });
    return el;
}

// Dream-physique progress: % to goal, on-track verdict, ETA — the north star.
function buildPhysique(d) {
    const vColor = { ahead: '#34d399', on_track: '#34d399', steady: '#fbbf24', behind: '#fb7185', just_started: '#9ca3af' };
    const c = vColor[d.verdict] || '#818cf8';
    const el = document.createElement('div'); el.className = 'tcard tcard-phys';
    const head = document.createElement('div'); head.className = 'tcard-title'; head.textContent = 'Tracking to your dream physique'; el.appendChild(head);

    const body = document.createElement('div'); body.className = 'tcard-phys-body';
    const main = document.createElement('div'); main.className = 'tcard-phys-main';
    const pctRow = document.createElement('div'); pctRow.className = 'tcard-phys-pctrow';
    pctRow.innerHTML = `<span class="tcard-phys-pct" style="color:${c}">${d.step_pct}%</span><span class="tcard-phys-pctlbl">to your goal</span>`;
    main.appendChild(pctRow);
    const track = document.createElement('div'); track.className = 'tcard-phys-track';
    const fill = document.createElement('div'); fill.style.width = Math.max(2, Math.min(100, d.step_pct)) + '%'; fill.style.background = c;
    track.appendChild(fill); main.appendChild(track);
    const pill = document.createElement('div'); pill.className = 'tcard-phys-verdict'; pill.style.color = c; pill.style.background = c + '22';
    pill.textContent = d.verdict_label || ''; main.appendChild(pill);
    if (d.eta_weeks != null) { const eta = document.createElement('div'); eta.className = 'tcard-phys-eta'; eta.textContent = `~${d.eta_weeks} week${d.eta_weeks === 1 ? '' : 's'} to goal at this pace`; main.appendChild(eta); }
    body.appendChild(main);
    if (d.goal_image) { const img = document.createElement('img'); img.className = 'tcard-phys-img'; img.src = d.goal_image; img.alt = 'Dream physique'; img.loading = 'lazy'; body.appendChild(img); }
    el.appendChild(body);

    const stats = [];
    if (d.adherence_pct != null) stats.push(['Consistency', d.adherence_pct + '%']);
    if (d.week_score != null) stats.push(['Week score', d.week_score]);
    if (d.weight) stats.push(['Weight', d.weight.value + ' ' + d.weight.unit + (d.weight.delta != null && d.weight.delta !== 0 ? ` (${d.weight.delta > 0 ? '+' : ''}${d.weight.delta})` : '')]);
    if (stats.length) {
        const row = document.createElement('div'); row.className = 'tcard-phys-stats';
        stats.forEach(([l, v]) => { const s = document.createElement('div'); s.innerHTML = `<span>${l}</span><b>${v}</b>`; row.appendChild(s); });
        el.appendChild(row);
    }
    return el;
}

// Small SVG sparkline of weekly scores (0–100), last point emphasised.
function reviewSparkline(scores, scoreColor) {
    const w = 84, h = 34, pad = 4, n = scores.length;
    const x = i => pad + (i * (w - 2 * pad)) / (n - 1);
    const y = v => h - pad - (Math.max(0, Math.min(100, v)) / 100) * (h - 2 * pad);
    const wrap = document.createElement('div'); wrap.className = 'tcard-review-spark';
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`); svg.setAttribute('width', w); svg.setAttribute('height', h);
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    path.setAttribute('points', scores.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' '));
    path.setAttribute('fill', 'none'); path.setAttribute('stroke', '#6366f1'); path.setAttribute('stroke-width', '2');
    path.setAttribute('stroke-linejoin', 'round'); path.setAttribute('stroke-linecap', 'round');
    svg.appendChild(path);
    const last = scores[n - 1];
    const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    dot.setAttribute('cx', x(n - 1)); dot.setAttribute('cy', y(last)); dot.setAttribute('r', '2.6');
    dot.setAttribute('fill', scoreColor(last)); svg.appendChild(dot);
    wrap.appendChild(svg);
    const lbl = document.createElement('div'); lbl.className = 'tcard-review-sparklbl'; lbl.textContent = `${n}-wk trend`;
    wrap.appendChild(lbl);
    return wrap;
}

// Weekly review: the week's score + momentum + scorecard — domains with WoW deltas, wins, next week.
function buildReview(d) {
    const sColor = s => ({ good: '#34d399', ok: '#fbbf24', low: '#fb7185', neutral: '#9ca3af' })[s] || '#9ca3af';
    const scoreColor = v => v >= 75 ? '#34d399' : v >= 50 ? '#fbbf24' : '#fb7185';
    const el = document.createElement('div'); el.className = 'tcard tcard-review';
    const eyebrow = document.createElement('div'); eyebrow.className = 'tcard-review-eyebrow';
    eyebrow.textContent = 'Week in review' + (d.range ? ' · ' + d.range : ''); el.appendChild(eyebrow);

    // Headline score + momentum + streak + trend sparkline.
    if (d.score != null) {
        const top = document.createElement('div'); top.className = 'tcard-review-scorewrap';
        const left = document.createElement('div');
        const num = document.createElement('div'); num.className = 'tcard-review-score'; num.style.color = scoreColor(d.score);
        num.textContent = d.score; const out = document.createElement('span'); out.textContent = '/100'; num.appendChild(out);
        left.appendChild(num);
        const meta = document.createElement('div'); meta.className = 'tcard-review-scoremeta';
        if (d.score_delta != null && d.score_delta !== 0) {
            const up = d.score_delta > 0;
            meta.innerHTML = `<span style="color:${up ? '#34d399' : '#fb7185'}">${up ? '▲' : '▼'} ${Math.abs(d.score_delta)} vs last week</span>`;
        } else { meta.textContent = 'this week'; }
        if (d.streak >= 2) { const s = document.createElement('span'); s.className = 'tcard-review-streak'; s.textContent = `🔥 ${d.streak}-wk streak`; meta.appendChild(s); }
        left.appendChild(meta);
        top.appendChild(left);
        if (Array.isArray(d.trend) && d.trend.length >= 2) top.appendChild(reviewSparkline(d.trend, scoreColor));
        el.appendChild(top);
    }
    if (d.headline) { const h = document.createElement('div'); h.className = 'tcard-review-headline'; h.textContent = d.headline; el.appendChild(h); }

    (d.metrics || []).forEach(m => {
        const row = document.createElement('div'); row.className = 'tcard-review-row';
        const dot = document.createElement('i'); dot.style.background = sColor(m.state);
        const l = document.createElement('div'); l.className = 'tcard-review-rl';
        l.innerHTML = `<span class="tcard-review-rlabel">${m.label || ''}</span>${m.sub ? `<span class="tcard-review-rsub">${m.sub}</span>` : ''}`;
        const right = document.createElement('div'); right.className = 'tcard-review-rr';
        const v = document.createElement('div'); v.className = 'tcard-review-rval'; v.textContent = m.value || '';
        right.appendChild(v);
        if (m.delta && m.delta.text) {
            const dd = document.createElement('div'); dd.className = 'tcard-review-rdelta';
            dd.style.color = m.delta.good ? '#34d399' : '#fb7185';
            dd.textContent = (m.delta.good ? '▲ ' : '▼ ') + m.delta.text;
            right.appendChild(dd);
        }
        row.append(dot, l, right); el.appendChild(row);
    });

    const list = (items, cls, sym) => {
        if (!items || !items.length) return;
        const wrap = document.createElement('ul'); wrap.className = 'tcard-review-list ' + cls;
        items.forEach(t => { const li = document.createElement('li'); li.innerHTML = `<span>${sym}</span> ${t}`; wrap.appendChild(li); });
        el.appendChild(wrap);
    };
    list(d.wins, 'tcard-review-wins', '▲');
    list(d.watch, 'tcard-review-watch', '!');

    if (d.next && (d.next.text || d.next.verdict)) {
        const n = document.createElement('div'); n.className = 'tcard-review-next';
        n.innerHTML = `<span class="tcard-review-nlabel">Next week</span> ${d.next.text || d.next.verdict}`;
        el.appendChild(n);
    }
    return el;
}

function renderCards(root) {
    root.querySelectorAll('pre > code.language-titan-card').forEach((code) => {
        const pre = code.parentElement;
        if (!pre || pre.dataset.card) return;
        let d;
        try { d = JSON.parse(code.textContent); } catch (_) { return; } // leave malformed blocks as code
        let card = null;
        if (d.type === 'physique') card = buildPhysique(d);
        else if (d.type === 'review') card = buildReview(d);
        else if (d.type === 'memory') card = buildMemory(d);
        else if (d.type === 'autoreg') card = buildAutoreg(d);
        else if (d.type === 'program' || d.type === 'mesocycle') card = buildProgram(d);
        else if (d.type === 'fitness' || d.type === 'athlete') card = buildFitness(d);
        else if (d.type === 'readiness' || d.type === 'ring') card = buildRing(d.score, d.label, d.caption);
        else if (d.type === 'stats' || d.type === 'vitals') card = buildStats(d.title, d.items);
        else if (d.type === 'stat' || d.type === 'metric') card = buildStat(d);
        else if (d.type === 'sparkline' || d.type === 'trend') card = buildSparkline(d);
        else if (d.type === 'cycle') card = buildCycle(d);
        else if (d.type === 'bioage') card = buildBioage(d);
        else if (d.type === 'checkin') card = buildCheckin(d);
        else if (d.type === 'sleep') card = buildSleep(d);
        else if (d.type === 'strain') card = buildStrain(d);
        else if (d.type === 'markers' || d.type === 'bloodwork') card = buildMarkers(d);
        else if (d.type === 'workout') card = buildWorkout(d);
        else if (d.type === 'macros') card = buildMacros(d);
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
