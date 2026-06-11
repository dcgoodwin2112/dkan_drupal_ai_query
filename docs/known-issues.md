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
([`dkan_mcp_server/modules/dkan_query_tools/src/Tool/DatastoreTools.php:1100`](https://git.drupalcode.org/project/dkan_mcp_server/-/blob/1.0.x/modules/dkan_query_tools/src/Tool/DatastoreTools.php))
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

**Mitigation in place.**
[`DatastoreTools::validateOperators()`](https://git.drupalcode.org/project/dkan_mcp_server/-/blob/1.0.x/modules/dkan_query_tools/src/Tool/DatastoreTools.php)
runs after `canonicalizeOperators()` and rejects any condition whose
operator is missing from DKAN's enum. Returns a structured error
naming the property and listing the allowed operators:

> `Invalid condition for property "population": operator is empty.
> Operator must be one of: =, <>, <, <=, >, >=, like, between, in,
> not in, contains, starts with, match.`

The agent now gets actionable information instead of a black-box
schema error, and as a side benefit the validator catches any other
invalid operator a future model might emit (e.g. `equals`, `lt`,
`>>>`).

**Failure mode by model — before and after the validator.** Confirmed
via the same conversation prompt against the Crime Data resource:

| Model | Before (generic schema error) | After (friendly enum error) |
|---|---|---|
| Claude Haiku 4.5 | 8 retries → **hallucinated** wrong cities (listed >1M cities as "<1M") | 8 retries → "task not solvable" — **no false data** |
| OpenAI `gpt-5.4-mini` | 4 retries → vague "filter syntax not accepted" refusal | 2 retries → refuse with operator-specific framing ("supported comparison operators require a valid operator") |

The validator does not stop the model from emitting empty operators in
the first place — the upstream cause is shared with the operator
HTML-encoding entry above (models mishandle the `< / >` character
class). What it does is convert the worst outcome (hallucination)
into a graceful failure, and shorten GPT's retry loop.

**What's been tried.** A v10 prompt experiment that explicitly told
the agent "operators must be literal characters in the JSON" did not
move the needle on either model — see the operator HTML-encoding
entry above for details. The server-side validator is the only
intervention that produced a measurable behavior change.

**Status.** Mitigated. Worst-case behavior (Haiku hallucination) is
gone. Open follow-ups, all low-priority:
- The `&lt;` HTML-encoded form would be the *better* failure mode
  here (decoder fixes it silently). Whatever causes the model to
  drop `<` entirely instead of escaping it is upstream; not worth
  chasing further until a model-version bump changes behavior.
- Haiku's "task not solvable" message is less informative than GPT's
  structured refuse. That's an `ai_agents` framework UX thing — out
  of scope for this module.
