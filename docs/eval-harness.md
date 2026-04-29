# Eval harness

Drush-driven eval harness for measuring agent accuracy. Every accuracy
PR must include an eval re-run; the [changelog](changelog.md) records
the per-phase delta against the committed baseline.

## Quickstart

```bash
ddev drush dkan-aiq:eval                              # Run the full golden set
ddev drush dkan-aiq:eval --case=foo_bar               # Run one case by id
ddev drush dkan-aiq:eval --model=claude-sonnet-4-6    # Override the model
ddev drush dkan-aiq:eval --sleep-seconds=35           # Pause between cases for rate limits
ddev drush dkan-aiq:eval --no-cache-clear             # Skip drupal_flush_all_caches between cases
ddev drush dkan-aiq:eval --label=my-experiment        # Custom label in the output filename
```

The default provider/model is Anthropic + `claude-haiku-4-5-20251001` at
temperature 0. Haiku 4.5 is fast enough to make a 10-case run finish in
under three minutes and matches production cost expectations. The
`--sleep-seconds` flag exists because Haiku's tier-1 rate limits will
trip on a back-to-back run; 35s spacing has proven safe.

## Output

| Path | Format |
|---|---|
| `sites/default/files/private/ai-eval/run-{label}.jsonl` | One JSON object per case (machine-readable). |
| `sites/default/files/private/ai-eval/run-{label}.md` | Per-run summary table (human-readable). |

`{label}` defaults to a timestamp; pass `--label=name` to override (the
phase eval runs in `tests/eval/phase{N}/` use this).

## How the runner differs from the controller

`EvalRunner` invokes the `dkan_data_query` agent directly via
`agent.solve()`. It bypasses `NlQueryController`, the polling endpoint,
and `PrivateTempStore` (CLI runs have no session). Same agent, same
tools, same prompt — but no HTTP, no polling overhead, and no session
contention between concurrent cases.

If a case ever passes in the runner but fails in the controller (or vice
versa), that's a real divergence to investigate; switch to a
controller-driven smoke test by issuing the same question via
`curl -X POST /api/dkan-ai-query/start`. So far this hasn't happened.

The runner reads the active prompt version through
[`SystemPromptLoader`](system-prompt.md), so eval results carry a
`prompt_version` field — bumping the prompt produces a clean
before/after line in the JSONL.

## Golden case schema

Cases live in `tests/eval/golden_set.yml`:

```yaml
- id: crime_houston_violent_total
  question: "How many violent crimes were reported in Houston?"
  expected_dataset_id: d460252e-d42c-474a-9ea9-5287b1d595f6   # null for refusal cases
  expected_columns_used: [city, violent_crimes]                 # advisory
  expected_answer_pattern: '2[,.\s]?2008'                       # case-insensitive regex
  expected_refusal: false
  expected_failure_category: null                               # set to dsl_limitation when applicable
  notes: "Why this case exists."
```

For refusal cases, set `expected_refusal: true` and leave
`expected_dataset_id` null. For DSL-limit cases, set
`expected_failure_category: dsl_limitation` so a graceful refusal scores
as a pass and a confident-but-wrong query scores as a `dsl_limitation`
failure (the trigger metric for the conditional Phase 7 raw-SQL escape).

To find dataset UUIDs and column names for new cases:

```bash
ddev drush dkan:list_datasets
ddev drush dkan:get_datastore_schema --resource_id=<id>
```

## Failure taxonomy

`CaseEvaluator` categorizes every failure into one of these buckets:

| Category | Meaning |
|---|---|
| `wrong_dataset_selected` | Agent landed on the wrong dataset. |
| `hallucinated_column` | Final answer references a column the schema does not contain. |
| `valid_query_wrong_intent` | Query ran but answered the wrong question. |
| `correct_query_wrong_summary` | Query was right; the natural-language summary mangled it. |
| `should_have_refused` | Answered when the case expected a refusal. |
| `should_have_answered` | Refused when the case expected an answer. |
| `tool_error_not_surfaced` | Tool failed; user-facing answer didn't acknowledge it. |
| `dsl_limitation` | Question genuinely cannot be expressed in `DatastoreQuery`. |

The `dsl_limitation` rate is the trigger signal for Phase 7. After Phase
3 it sat at 10% (one flaky case); after Phase 4 it dropped to 0%.

## Baseline and per-phase runs

| Run | Pass rate | File |
|---|---|---|
| Baseline (pre-Phase 1) | 40% | `tests/eval/baseline/run-baseline.{jsonl,md}` |
| Phase 2 | 50% | `tests/eval/phase2/run-phase2-*.{jsonl,md}` |
| Phase 3 | 90% | `tests/eval/phase3/run-phase3-final.{jsonl,md}` |
| Phase 4 | 100% | `tests/eval/phase4/run-phase4-full.{jsonl,md}` |

PR descriptions for accuracy work should show the delta against the
previous run. See [changelog.md](changelog.md) for the per-phase summary.

## Eval data hygiene

Eval conversations are flagged with `is_eval = TRUE` on the
`dkan_aiq_conversation` entity so they don't pollute the user-visible
history list. The runner clears `SchemaContextBuilder` cache and
per-thread artifact storage between cases so one case never contaminates
the next.
