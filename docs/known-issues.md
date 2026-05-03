# Known issues and limitations

Open issues with the agent's tool-call hygiene that have a workaround
in place but no clean fix yet. Each entry records the symptom, what's
already mitigating it, what's been tried, and what's still on the
table. Add new entries when you find a bug worth tracking but not
worth blocking on; remove them when the bug is gone or moved to a
real ticket.

## Operator HTML-encoding in `query_datastore` conditions

**Symptom.** Both Anthropic (Claude Haiku 4.5) and OpenAI
(`gpt-5.4-mini`) emit comparison operators HTML-entity-encoded inside
tool-call JSON — `"&gt;"` instead of `">"`, `"&gt;="` instead of
`">="`, `"&lt;"` instead of `"<"`. DKAN's `DatastoreQuery` enforces a
strict operator enum and rejects the encoded form.

**Mitigation in place.** `DatastoreTools::canonicalizeOperators()`
([`web/modules/custom/dkan_query_tools/src/Tool/DatastoreTools.php:1100`](../../dkan_query_tools/src/Tool/DatastoreTools.php))
runs `html_entity_decode` on every condition's `operator` field before
the query executes. Called from both `queryDatastore()` (line 147) and
`queryDatastoreJoin()` (line 608). Touches `operator` only — `value`
may legitimately contain HTML entities. End users never see the bug.

**What's been tried.** A v10 prompt experiment added an explicit
"operators must be literal characters in the JSON" instruction in the
DSL quick reference section, enumerating the wrong forms. **It did not
move the needle for either model in three trials apiece.** Both still
emitted `&gt;` and `&gt;=` on `>` / `>=` queries with the new
instruction live in the agent's `system_prompt`. Reverted; the
decoder remains the only effective defense.

**Open ideas.**
- Move the rule to the "Hard rules" section instead of the DSL quick
  reference. (Two trials suggest placement isn't the issue, but the
  experiment was small.)
- Strip HTML entities at the FunctionCall plugin layer (input
  normalizer) instead of inside `DatastoreTools`, so other consumers
  benefit too.
- Add a metric: count canonicalize hits per provider/model in the
  eval harness output to track whether new model versions improve.

**Status.** Cosmetic — no user-visible failure. Decoder is doing the
work. Don't remove the decoder.

## Stringified tool arguments on `<` queries

**Symptom.** Both models, on at least the simplest "less than"
question, serialize structured tool arguments as JSON-encoded strings
instead of their declared types. Observed shapes:

```jsonc
{
  "resource_id": "…",
  "columns": "city,population",                              // should be array
  "conditions": "[{\"property\":\"population\",…}]",         // should be array
  "limit": "500"                                             // should be number
}
```

The FunctionCall input schema rejects all three with "JSON Schema
validation failed" before any business logic runs. The agent retries
the same shape several times, then exhausts its iteration budget.

**Failure mode is model-dependent and worse than the operator bug:**

| Model | Retries before giving up | Final outcome |
|---|---|---|
| Claude Haiku 4.5 | 8 | **Hallucinated** answer from rows cached during prior queries — listed cities ≥1M as "<1M" without acknowledging the underlying queries failed |
| OpenAI `gpt-5.4-mini` | 4 | Properly invoked `refuse(reason_category="other")` with an honest "filter syntax is not being accepted" message |

GPT's refuse path is acceptable; Haiku's hallucination is not.

**Mitigation in place.** None. The schema rejection is intentional
(it's catching real bugs); the agent's retry loop is bounded; nothing
covers the gap.

**Open ideas.**
- Coerce common stringified shapes at the FunctionCall plugin layer:
  if `conditions` arrives as a string, attempt `json_decode`; same for
  `columns`. Cheap, defensive, and matches the operator-decoder
  pattern.
- Tighten the `query_datastore` system-prompt examples to show the
  array shape directly inside a `<` example (the only existing example
  uses `=`). The model may be conflating "string filter" with the JSON
  shape when picking inequality operators.
- Add a guard in the agent loop: when N consecutive identical
  schema-validation failures hit the same tool call signature, force
  a `refuse(other)` instead of letting Haiku-class models continue
  into hallucination territory. Requires touching the `ai_agents`
  framework or the controller's solve loop.

**Status.** Active concern. Reproducible on the simplest `<` query
("cities with population less than 1000000"). Decide if this gets a
prompt-only fix attempt or a server-side coercion before adding more
issues to this file.
