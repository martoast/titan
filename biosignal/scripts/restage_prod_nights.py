"""Re-stage REAL sealed nights from their stored raw windows — read-only, no DB writes.

PSG cross-validation says whether a stager is right in general. This says what it does to the actual
people on this box, which is the question that matters before swapping a model. It rebuilds each
night's payload exactly the way `SealNightJob::stageSparse` does — `result_refs.epoch_motion` /
`epoch_hr` / `epoch_rmssd` per ppg_raw window, epoch index `floor((window_start - t0) / 30)`, with
`t0` = the earliest contributing window_start — and stages it in-process.

Nothing is written anywhere: no DB update, no HTTP call into the app. Staging is pure computation.
(See the standing rule about verification that mutates its own subject — this deliberately does not.)

Usage, inside the biosignal image with the repo mounted at /srv:
    python scripts/restage_prod_nights.py --dsn "host=... user=... password=... db=titan" --profile 1
or, simpler, feed it a JSON dump produced by the caller:
    python scripts/restage_prod_nights.py --payloads nights.json
where nights.json is [{"id":190,"slept_at":"2026-08-06","stored":{...},"accel":[...],"hr":[...],
"rmssd":[...],"epochs":[...],"start":"...","end":"..."}].
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from app.core import staging  # noqa: E402


def longest_deep_bout_min(hyp: list[str]) -> float:
    best = run = 0
    for s in hyp:
        run = run + 1 if s == "deep" else 0
        best = max(best, run)
    return best * staging.EPOCH_SEC / 60.0


def stage_one(p: dict) -> dict:
    m = staging.stage_night(
        p["accel"],
        p.get("hr"),
        p.get("rmssd"),
        p["start"],
        p["end"],
        sample_epochs=p["epochs"],
    )
    hyp = m["hypnogram_30s"]
    asleep = m["deep_min"] + m["rem_min"] + m["light_min"]
    return {
        "deep_min": m["deep_min"],
        "rem_min": m["rem_min"],
        "light_min": m["light_min"],
        "awake_min": m["awake_min"],
        "asleep_min": asleep,
        "deep_frac": (m["deep_min"] / asleep) if asleep else 0.0,
        "rem_frac": (m["rem_min"] / asleep) if asleep else 0.0,
        "longest_deep_bout_min": longest_deep_bout_min(hyp),
        "coverage": m["coverage"],
        "coverage_sampled": m.get("coverage_sampled"),
        "stages_low_confidence": m.get("stages_low_confidence"),
        "stages_impossible": m.get("stages_impossible"),
        "quality": m["quality"],
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--payloads", required=True, help="JSON file of prebuilt night payloads")
    ap.add_argument("--json", default=None, help="write full results here")
    args = ap.parse_args()

    nights = json.loads(Path(args.payloads).read_text())
    rows = []
    print(f"{'id':>5} {'date':<11} {'stored deep':>12} {'restaged deep':>14} "
          f"{'bout':>7} {'rem':>7} {'impossible':>11}")
    for p in nights:
        try:
            r = stage_one(p)
        except Exception as e:                       # a night that cannot stage is a result too
            print(f"{p['id']:>5} {p.get('slept_at',''):<11}  ERROR {e}")
            continue
        st = p.get("stored") or {}
        sd = st.get("deep_min")
        sa = (st.get("deep_min", 0) or 0) + (st.get("rem_min", 0) or 0) + (st.get("light_min", 0) or 0)
        stored_frac = (sd / sa * 100) if (sd is not None and sa) else float("nan")
        print(f"{p['id']:>5} {p.get('slept_at',''):<11} {stored_frac:11.1f}% "
              f"{r['deep_frac']*100:13.1f}% {r['longest_deep_bout_min']:6.1f}m "
              f"{r['rem_frac']*100:6.1f}% {str(r['stages_impossible']):>11}")
        rows.append({"id": p["id"], "slept_at": p.get("slept_at"), "stored": st, "restaged": r})

    if rows:
        import statistics
        print(f"\n{len(rows)} nights")
        print(f"  mean restaged deep fraction : {statistics.mean(r['restaged']['deep_frac'] for r in rows)*100:.1f}%")
        print(f"  mean restaged rem  fraction : {statistics.mean(r['restaged']['rem_frac'] for r in rows)*100:.1f}%")
        print(f"  nights over 35% deep        : {sum(1 for r in rows if r['restaged']['deep_frac'] > 0.35)}")
        print(f"  nights with >90m deep bout  : {sum(1 for r in rows if r['restaged']['longest_deep_bout_min'] > 90)}")
    if args.json:
        Path(args.json).write_text(json.dumps(rows, indent=2))
        print(f"\nwrote {args.json}")


if __name__ == "__main__":
    main()
