#!/usr/bin/env python3
"""Compare two or more eval JSONL runs.

Usage:
  python3 compare-runs.py run-A.jsonl run-B.jsonl [run-C.jsonl ...]

Prints a per-case outcome / duration / tool-call delta table plus
aggregate summaries. Designed for the three-arm dictionary lift
investigation but works on any pair-or-more of dkan-aiq:eval runs.
"""

from __future__ import annotations

import json
import sys
from collections import Counter
from pathlib import Path


def load(path: str) -> dict[str, dict]:
    out: dict[str, dict] = {}
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            d = json.loads(line)
            out[d["case_id"]] = d
    return out


def label_for(path: str) -> str:
    name = Path(path).name
    if name.startswith("run-") and name.endswith(".jsonl"):
        return name[4:-6]
    return name


def tool_count(rec: dict) -> int:
    return len(rec.get("tool_calls") or [])


def tool_counter(rec: dict) -> Counter:
    return Counter(tc.get("tool", "?") for tc in (rec.get("tool_calls") or []))


def has_tool(rec: dict, name: str) -> bool:
    return any(tc.get("tool") == name for tc in (rec.get("tool_calls") or []))


def fmt_outcome(rec: dict) -> str:
    if not rec:
        return "missing"
    o = rec.get("outcome", "?")
    if o == "fail" and rec.get("failure_category"):
        return f"fail/{rec['failure_category']}"
    return o


def main(paths: list[str]) -> int:
    if len(paths) < 2:
        print("Need at least two JSONL paths.", file=sys.stderr)
        return 1

    runs = [(label_for(p), load(p)) for p in paths]
    all_cases = sorted({cid for _, r in runs for cid in r})

    # Per-case table.
    headers = ["case"] + [lbl for lbl, _ in runs] + [f"{lbl}_calls" for lbl, _ in runs]
    widths = [40] + [22] * len(runs) + [10] * len(runs)
    print("  ".join(h.ljust(w) for h, w in zip(headers, widths)))
    print("-" * sum(widths) + "-" * (2 * (len(headers) - 1)))
    for cid in all_cases:
        outcomes = [fmt_outcome(r.get(cid, {})) for _, r in runs]
        calls = [str(tool_count(r.get(cid, {}))) for _, r in runs]
        row = [cid] + outcomes + calls
        print("  ".join(c.ljust(w) for c, w in zip(row, widths)))

    print()
    print("=" * 80)
    print("Aggregates")
    print("=" * 80)

    # Aggregate pass rates and durations.
    print(f"\n{'metric':35s}  " + "  ".join(f"{lbl:>15s}" for lbl, _ in runs))
    metrics: dict[str, list[str]] = {}
    for lbl, run in runs:
        passes = sum(1 for r in run.values() if r.get("outcome") == "pass")
        total = len(run)
        durations = [r.get("duration_ms", 0) for r in run.values()]
        avg_dur = sum(durations) / len(durations) if durations else 0
        total_calls = sum(tool_count(r) for r in run.values())
        metrics.setdefault("pass rate", []).append(f"{passes}/{total} ({passes/total*100:.1f}%)" if total else "n/a")
        metrics.setdefault("mean duration (ms)", []).append(f"{avg_dur:.0f}")
        metrics.setdefault("total tool calls", []).append(str(total_calls))
    for k, vals in metrics.items():
        print(f"{k:35s}  " + "  ".join(f"{v:>15s}" for v in vals))

    # Tool-frequency breakdown.
    print()
    print(f"{'tool':35s}  " + "  ".join(f"{lbl:>15s}" for lbl, _ in runs))
    print("-" * (35 + 17 * len(runs)))
    counters = [(lbl, sum((tool_counter(r) for r in run.values()), Counter())) for lbl, run in runs]
    all_tools = sorted({t for _, c in counters for t in c})
    for t in all_tools:
        print(f"{t:35s}  " + "  ".join(f"{c.get(t, 0):>15d}" for _, c in counters))

    # Dictionary-tool redundancy: cases where get_data_dictionary AND
    # get_datastore_schema were called for the same resource.
    print()
    print("Dictionary-tool redundancy (calls to BOTH get_data_dictionary and get_datastore_schema):")
    for lbl, run in runs:
        redundant = sum(1 for r in run.values()
                        if has_tool(r, "get_data_dictionary") and has_tool(r, "get_datastore_schema"))
        with_dict = sum(1 for r in run.values() if has_tool(r, "get_data_dictionary"))
        print(f"  {lbl:30s}  {redundant:>3d} redundant / {with_dict:>3d} called dictionary")

    # Per-case outcome diffs across runs (Δ).
    if len(runs) >= 2:
        print()
        print("Cases with non-uniform outcomes across runs:")
        for cid in all_cases:
            outs = [fmt_outcome(r.get(cid, {})) for _, r in runs]
            if len(set(outs)) > 1:
                summary = " | ".join(f"{lbl}={o}" for (lbl, _), o in zip(runs, outs))
                print(f"  {cid:40s}  {summary}")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
