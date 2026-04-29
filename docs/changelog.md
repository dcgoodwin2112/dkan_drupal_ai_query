# Accuracy effort changelog

Per-phase eval delta against the committed
`tests/eval/baseline/run-baseline.{jsonl,md}`. Numbers come from the
phase JSONL files committed alongside each PR; rerun
`drush dkan-aiq:eval` to reproduce.

## Phase 1: Eval harness ([PR #4](https://github.com/dcgoodwin2112/dkan_drupal_ai_query/pull/4))

Eval: established the 40% baseline (4/10 pass). DSL limitation: 30%.
Highlights: `drush dkan-aiq:eval`, golden set YAML, baseline run files
committed. No agent changes — measurement only. See
[eval-harness.md](eval-harness.md).

## Phase 2: Introspection gaps ([PR #5](https://github.com/dcgoodwin2112/dkan_drupal_ai_query/pull/5))

Eval: 40% → 50% (Δ +10 pp). DSL limitation: 30% → 30%.
Highlights: `sample_rows` and `distinct_values` tools, `dataset_caveat`
config entity replacing static YAML, freshness/coverage caveats
unblocked the `varicella_out_of_coverage` case. See
[dataset-caveats.md](dataset-caveats.md).

## Phase 3: System prompt v2 ([PR #6](https://github.com/dcgoodwin2112/dkan_drupal_ai_query/pull/6))

Eval: 50% → 90% (Δ +40 pp). DSL limitation: 30% → 10%.
Highlights: file-backed versioned prompt via `BuildSystemPromptEvent`,
`RefuseTool` plugin + `RefusalCollector` service for structured refusals,
`prompt_version` provenance on every persisted message. Three cases
moved fail → pass (`refusal_no_matching_dataset`,
`refusal_write_request`, `dsl_yoy_varicella`); zero regressions. See
[system-prompt.md](system-prompt.md) and [refusal-flow.md](refusal-flow.md).

## Phase 4: Defense in depth ([PR #7](https://github.com/dcgoodwin2112/dkan_drupal_ai_query/pull/7))

Eval: 90% → 100% (Δ +10 pp). DSL limitation: 10% → 0%.
Highlights: structured `unknown_column` errors with `available_columns`
on every datastore query, sanity flags on every result,
`UnknownColumnGuardSubscriber` trips a `repeated_unknown_column`
refusal at three strikes, prompt v2 requires acknowledging sanity
flags, read-only MariaDB role documented (auto-switch deferred). The
previously flaky `dsl_above_avg_crime` case stabilized as a pass. See
[refusal-flow.md](refusal-flow.md) and the upstream
[tool-responses.md](../../dkan_query_tools/docs/tool-responses.md).

## Phase 5: Provenance and UI ([PR #8](https://github.com/dcgoodwin2112/dkan_drupal_ai_query/pull/8))

Eval: 100% → 100% (no change expected; UX-side phase).
Highlights: `provenance` block on every data artifact (executed_at,
tool, row_count, total_rows, sanity_flags, query_summary), widget
renders provenance as a collapsible panel and structured refusals as a
red-bordered card, zero-row results render summary + provenance instead
of bailing silently. See [provenance.md](provenance.md).

## Cumulative delta

| Phase | Pass rate | DSL limitation rate |
|---|---|---|
| Baseline | 40% | 30% |
| Phase 2 | 50% | 30% |
| Phase 3 | 90% | 10% |
| Phase 4 | 100% | 0% |
| Phase 5 | 100% | 0% |

Phase 5 is UX/audit work; pass rate stays flat because the eval scores
agent output, not widget rendering.
