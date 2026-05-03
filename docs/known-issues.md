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

## Empty `operator` field in conditions on `<` queries

**Symptom.** When asked a "less than" question, both Claude Haiku 4.5
and OpenAI `gpt-5.4-mini` emit `"operator": ""` (empty string) in the
conditions array — apparently dropping the literal `<` character on
its way to the tool-call JSON. Decoded from saved conversation
artifacts:

```json
{
  "property": "population",
  "value": "1000000",
  "operator": ""
}
```

Same root cause as the operator HTML-encoding entry above (models
mishandle the `<` / `>` character class in tool-call output), just a
more degenerate failure mode for `<` — instead of producing `&lt;`
that the decoder can fix, it produces nothing at all. The rest of the
input is correctly shaped (`columns` and `conditions` are strings as
the FunctionCall schema declares; `limit` is a proper integer).

DKAN's `RootedJsonData` rejects the empty operator with the cryptic
`"JSON Schema validation failed."` (no field name, no enum hint), so
the agent has no way to diagnose and self-correct.

**Failure mode by model.** Both confirmed via the saved conversation
artifacts (`dkan_aiq_messages.artifacts`, conversations 136 and 137):

| Model | Retries before giving up | Final outcome |
|---|---|---|
| Claude Haiku 4.5 | 8 (all with same empty operator) | **Hallucinated** answer from rows cached during prior queries — listed cities >1M as "<1M" without acknowledging the underlying queries failed |
| OpenAI `gpt-5.4-mini` | 4 (varied `value` from string to int and back, never the operator) | Properly invoked `refuse(reason_category="other")` with an honest "filter syntax is not being accepted" message |

Haiku's hallucination is the user-visible harm. GPT's refuse path is
acceptable but still a degraded experience.

**Mitigation in place.** None yet — the schema rejection is intentional
(the empty operator IS invalid), but the error message has no
information the agent can act on.

**Recommended fix.** Add `validateOperators()` next to the existing
`canonicalizeOperators()` in
[`web/modules/custom/dkan_query_tools/src/Tool/DatastoreTools.php`](../../dkan_query_tools/src/Tool/DatastoreTools.php).
Walks the same nested condition-group shape, runs **after**
canonicalize so HTML-encoded operators get fixed first. Returns the
first invalid operator encountered with a structured error:

> `Invalid condition for property "population": operator field is
> empty. Operator must be one of: =, <>, <, <=, >, >=, like, contains,
> starts with, in, not in, between.`

This catches the empty-operator case AND any other invalid string a
future model might emit (e.g., `equals`, `lt`, `>>>`). The agent gets
a clear, actionable error and can either retry with a literal
operator or refuse with a real reason instead of a black-box.

**Other ideas considered, not chosen.**
- Coerce empty operator to a default — unsafe, no way to guess intent
  (`<`, `<=`, `=` give different answers).
- Tighten the system prompt to include a `<` example — the v10
  experiment showed prompt changes don't reliably move either model
  on the `< / >` character class. A friendly server-side error is a
  hard guarantee; a prompt instruction is best-effort.
- Loop guard at the agent layer (force `refuse(other)` after N
  identical failures) — would prevent Haiku's hallucination but
  doesn't help the agent recover. Server-side error message is
  strictly better.

**Status.** Reproducible on demand: ask "cities with population less
than 1000000" against the Crime Data resource. Fix is straightforward
(~1 hour incl. unit tests).
