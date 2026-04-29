# Refusal flow

Every refusal that reaches the user is a structured payload, not a
free-text apology. This is what makes refusals reliably scorable in eval
and renderable as a distinct UI card.

Two things produce a refusal:

1. **The agent calls `RefuseTool`** — voluntary refusal: no matching
   dataset, out of scope, DSL limit, etc.
2. **The 3-strikes guard trips** — involuntary refusal: the agent
   invented column names three times in one turn.

Both end up shaped identically and flow through `RefusalCollector`.

## RefuseTool

`#[FunctionCall(id: 'dkan_drupal_ai_query:refuse', function_name: 'refuse')]`
in `src/Plugin/AiFunctionCall/RefuseTool.php`.

Arguments:

| Arg | Required | Notes |
|---|---|---|
| `reason_category` | yes | One of the allowed categories below. Anything else is normalized to `other`. |
| `explanation` | yes | One or two plain-English sentences. No apology boilerplate. |
| `datasets_searched` | no | Comma-separated dataset titles or UUIDs the agent inspected before refusing. |

Allowed categories:

```
no_matching_dataset
out_of_scope
write_request
out_of_coverage
dsl_limitation
repeated_unknown_column
other
```

Output payload (set as the tool's readable output):

```json
{
  "refused": true,
  "reason_category": "dsl_limitation",
  "explanation": "Year-over-year change requires a window function or self-join, which the DKAN datastore query DSL does not support.",
  "datasets_searched": ["foreclosure-statistics-2012"]
}
```

## RefusalCollector

In-process registry keyed by thread id (a.k.a. agent runner id):

```php
$refusals = \Drupal::service('dkan_drupal_ai_query.refusal_collector');
$refusals->record($threadId, $payload);
$refusals->get($threadId);     // returns the last recorded payload, or NULL
$refusals->forget($threadId);  // called between eval cases
```

It exists alongside `ArtifactStorage` (which is `PrivateTempStore`-backed
and session-bound) because:

- Eval runs over Drush have no session.
- The HTTP controller's request runs in the same PHP process as
  `solve()`, so cross-process persistence isn't needed.
- Refusal scoring needs deterministic access to the structured payload
  even when the agent's final assistant turn includes text after the
  tool call.

After `solve()` returns, both `NlQueryController::start()` and the
`EvalRunner` read `RefusalCollector::get($threadId)` to decide whether
to render a refusal card / score the case as a refusal.

## The 3-strikes unknown-column guard

Without a guard, an agent that keeps inventing column names burns the
full `max_loops` budget retrying the same mistake. `UnknownColumnGuardSubscriber`
short-circuits this:

```
query_datastore call → DatastoreTools returns {error: "unknown_column", …}
        ↓
AgentToolFinishedExecutionEvent fires
        ↓
UnknownColumnGuardSubscriber  (priority 10)
   - Match function_name in {query_datastore, query_datastore_join}
   - Decode tool output; require error === 'unknown_column'
   - UnknownColumnCounter::bump(threadId)
   - If count < 3: do nothing — the agent gets the structured error
     (with `available_columns`) and self-corrects in the next iteration
   - If count >= 3:
       - Build a refusal payload with reason_category =
         'repeated_unknown_column' and the latest column / available list
         in the explanation
       - tool->setOutput(payload) — overwrites the error so the agent
         observes the refusal terminator
       - RefusalCollector::record(threadId, payload)
        ↓
ArtifactCaptureSubscriber  (priority 0)
   - Sees the rewritten output; captures it as a refusal artifact
     (same path as a voluntary RefuseTool call)
```

Trip threshold lives at `UnknownColumnCounter::tripThreshold()` (3). The
counter uses runner id when threadId is null (CLI eval has no thread id).

## Widget rendering

`Widget.prototype.renderRefusalInBubble` in `js/widget.js` produces:

```
.dkan-aiq-refusal               red-bordered card (border-left 4px #dc2626)
  .dkan-aiq-refusal-category    monospace badge ("dsl_limitation")
  .dkan-aiq-refusal-explanation plain-text body
  .dkan-aiq-refusal-searched    "Searched: dataset-a, dataset-b"
```

Override the appearance from a theme by targeting these classes; no
template overrides needed.

The poll endpoint dispatches on `artifact.type`:

- `data` → table + provenance (see [provenance.md](provenance.md))
- `chart` → Vega-Lite render
- `refusal` → refusal card

## Eval scoring

`CaseEvaluator` reads the `refused` boolean off the artifact / collector
result. Behavior:

| Case `expected_refusal` | Got refusal? | Outcome |
|---|---|---|
| `true` | yes | pass |
| `true` | no | fail (`should_have_refused`) |
| `false` | yes | fail (`should_have_answered`) |
| `false` | no | pass/fail by answer-pattern match |

For DSL-limit cases (`expected_failure_category: dsl_limitation`), a
graceful refusal scores as a pass; a confident-but-wrong query scores as
a `dsl_limitation` failure.
