"""Does per-epoch RMSSD actually improve sleep staging? The ablation the Walch data
couldn't do (it had no beat-to-beat HR).

MIT-BIH Polysomnographic DB (slpdb, PhysioNet): ECG + 30-s PSG sleep stages. We extract
REAL per-epoch HR + RMSSD from the ECG, then stage sleep with HR-only vs HR+RMSSD features,
leave-subjects-out. If HR+RMSSD lifts deep/REM, RMSSD is worth adding to our model — and
our Bangle computes exactly this signal. (slpdb has no actigraphy, so this isolates the
HRV contribution; wake detection is weaker here without motion.)

Usage: .venv/bin/python scripts/ablate_rmssd.py /tmp/slpdb
"""

from __future__ import annotations

import sys
from pathlib import Path

import numpy as np
import wfdb
import neurokit2 as nk
from sklearn.ensemble import HistGradientBoostingClassifier
from sklearn.metrics import classification_report, cohen_kappa_score
from sklearn.model_selection import GroupKFold

EPOCH, FS = 30, 250
STAGES = ["wake", "light", "deep", "rem"]
S2I = {s: i for i, s in enumerate(STAGES)}
STAGE_MAP = {"W": "wake", "1": "light", "2": "light", "3": "deep", "4": "deep", "R": "rem"}


def _fill(x):
    x = np.asarray(x, float)
    if np.isfinite(x).any():
        return np.where(np.isfinite(x), x, np.nanmedian(x))
    return np.zeros_like(x)


def _roll(x, w, fn):
    out = np.zeros_like(x, dtype=float); h = w // 2
    for i in range(len(x)):
        out[i] = fn(x[max(0, i - h):min(len(x), i + h + 1)])
    return out


def _rank(x):
    return np.argsort(np.argsort(x)) / max(1, len(x) - 1)


def process(rec: str):
    cache = Path(rec + ".proc.npz")
    if cache.exists():
        d = np.load(cache, allow_pickle=True)
        return d["hr"], d["rmssd"], d["lab"]
    r = wfdb.rdrecord(rec)
    ann = wfdb.rdann(rec, "st")
    ecg = r.p_signal[:, r.sig_name.index("ECG")]
    _, info = nk.ecg_peaks(nk.ecg_clean(ecg, sampling_rate=FS), sampling_rate=FS)
    rpk = np.asarray(info["ECG_R_Peaks"], float) / FS
    rr = np.diff(rpk) * 1000.0
    rr_t = rpk[1:]
    hr, rmssd, lab = [], [], []
    for k in range(len(ann.sample)):
        aux = ann.aux_note[k].strip()
        st = STAGE_MAP.get(aux.split()[0]) if aux else None
        t0 = ann.sample[k] / FS
        seg = rr[(rr_t >= t0) & (rr_t < t0 + EPOCH)]
        seg = seg[(seg >= 300) & (seg <= 2000)]
        hr.append(60000.0 / np.mean(seg) if seg.size >= 3 else np.nan)
        rmssd.append(min(float(np.sqrt(np.mean(np.diff(seg) ** 2))), 250.0) if seg.size >= 4 else np.nan)
        lab.append(st)
    keep = [i for i, l in enumerate(lab) if l is not None]
    hr = _fill(hr); rmssd = _fill(rmssd)
    hr, rmssd, lab = hr[keep], rmssd[keep], np.array([lab[i] for i in keep])
    np.savez(cache, hr=hr, rmssd=rmssd, lab=lab)
    return hr, rmssd, lab


def feats(hr, rmssd, use_rmssd: bool):
    n = len(hr)
    floor = np.percentile(hr, 5)
    cols = [hr - floor, _rank(hr), np.gradient(hr)]
    for w in (3, 9, 21):
        cols.append(_roll(hr, w, np.mean) - floor)
    for w in (5, 11, 21):
        cols.append(_roll(hr, w, np.std))
    if use_rmssd:
        cols += [rmssd, _rank(rmssd), np.gradient(rmssd)]
        for w in (3, 9, 21):
            cols.append(_roll(rmssd, w, np.mean))
    t = np.linspace(0, 1, n)
    cols += [t, np.cos(2 * np.pi * t)]
    return np.nan_to_num(np.column_stack(cols))


def evaluate(records, use_rmssd: bool):
    data, groups = [], []
    for i, (subj, hr, rmssd, lab) in enumerate(records):
        data.append((feats(hr, rmssd, use_rmssd), np.array([S2I[s] for s in lab])))
        groups.append(subj)
    X = np.vstack([x for x, _ in data]); y = np.concatenate([yy for _, yy in data])
    g = np.concatenate([[i] * len(data[i][1]) for i in range(len(data))])
    oof = np.zeros_like(y)
    for tr, te in GroupKFold(min(5, len(data))).split(X, y, g):
        clf = HistGradientBoostingClassifier(max_depth=5, max_iter=300, learning_rate=0.07,
                                             class_weight="balanced", random_state=0).fit(X[tr], y[tr])
        oof[te] = clf.predict(X[te])
    rec_deep = np.mean(oof[y == 2] == 2) if (y == 2).any() else float("nan")
    rec_rem = np.mean(oof[y == 3] == 3) if (y == 3).any() else float("nan")
    return cohen_kappa_score(y, oof), np.mean(oof == y), rec_deep, rec_rem


def main():
    d = Path(sys.argv[1] if len(sys.argv) > 1 else "/tmp/slpdb")
    recs = sorted({p.stem for p in d.glob("*.hea")})
    loaded = []
    for r in recs:
        try:
            hr, rmssd, lab = process(str(d / r))
            if len(lab) > 60:
                subj = r.rstrip("abx")  # slp01a/slp01b → same subject slp01
                loaded.append((subj, hr, rmssd, lab))
        except Exception as e:
            print(f"skip {r}: {e}")
    print(f"{len(loaded)} records, {len(set(s for s, *_ in loaded))} subjects\n")

    for tag, use in [("HR only       ", False), ("HR + RMSSD     ", True)]:
        k, acc, rd, rr = evaluate(loaded, use)
        print(f"  {tag} 4-class κ {k:.3f}  acc {acc*100:4.1f}%   deep recall {rd*100:4.1f}%   REM recall {rr*100:4.1f}%")


if __name__ == "__main__":
    main()
