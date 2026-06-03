# Provenance and sanity flags

Every successful `query_datastore` / `query_datastore_join` artifact
carries a `provenance` block so the user can audit exactly what query
ran, when, and what shape the result took. Sanity flags travel inside
that block; the prompt requires the agent to acknowledge any
non-default flag in its final answer.

The DatastoreTools-side contract for these flags lives at
[../../dkan_mcp_server/modules/dkan_query_tools/docs/tool-responses.md](../../dkan_mcp_server/modules/dkan_query_tools/docs/tool-responses.md).
This doc covers the agent-side capture and the widget rendering.

## Artifact shape

A `data` artifact in the poll response:

```json
{
  "type": "data",
  "tool": "query_datastore",
  "rows": [{"city": "Houston", "violent_crimes": 22008}],
  "count": 1,
  "schema": null,
  "input": {
    "resource_id": "rid__1",
    "resolved_resource_id": "rid__1",
    "distribution_uuid": "abc-...",
    "table_name": "datastore_<md5>",
    "columns": "city,violent_crimes",
    "conditions": "[{\"property\":\"city\",\"value\":\"Houston\",\"operator\":\"=\"}]",
    "limit": 100
  },
  "provenance": {
    "executed_at": "2026-04-29T15:42:11+00:00",
    "tool": "query_datastore",
    "row_count": 1,
    "total_rows": 1,
    "sanity_flags": {
      "zero_rows": false,
      "all_null_columns": [],
      "row_cap_hit": false,
      "coverage_warning": null
    },
    "query_summary": {
      "resource_id": "rid__1",
      "columns": "city,violent_crimes",
      "limit": 100,
      "conditions": [{"property": "city", "value": "Houston", "operator": "="}]
    }
  }
}
```

## Where each piece is built

| Key | Source |
|---|---|
| `executed_at` | `gmdate('c')` at capture time, ISO 8601 UTC. |
| `tool` | The FunctionCall function_name (`query_datastore` or `query_datastore_join`). |
| `row_count` | `count($rows)` after the limit. |
| `total_rows` | DKAN's pre-limit count, falling back to `result_count`. |
| `sanity_flags` | Passed through from `DatastoreTools` (see upstream doc). |
| `query_summary` | Built by `ArtifactCaptureSubscriber::buildQuerySummary()`. |

The whole block is built by `ArtifactCaptureSubscriber::buildProvenance()`
in `src/EventSubscriber/ArtifactCaptureSubscriber.php`. It runs on
`AgentToolFinishedExecutionEvent` at priority 0, after the 3-strikes
guard at priority 10 has had a chance to rewrite the output.

## Why `query_summary` decodes JSON

The LLM hands `conditions` and `expressions` to the tool as JSON-string
arguments. We decode them into structured arrays for the provenance
block so:

- The widget can render conditions as a readable table without parsing
  JSON in JavaScript.
- A future LLM-as-judge (Phase 6) can reason over structured query
  shape rather than re-parsing strings.

## Sanity flags in practice

| Flag | What the prompt requires |
|---|---|
| `zero_rows: true` | Final answer must say "no rows match" rather than invent context. |
| `row_cap_hit: true` | Answer must flag that the result is partial; suggest narrowing the filter. |
| `all_null_columns: [...]` | Answer must mention these columns are empty (often signals the wrong column was joined). |
| `coverage_warning: "..."` | Answer must surface the warning text and suggest verifying coverage via `get_datastore_stats`. |

The prompt v2 rule that wires this in lives in
`prompts/query_system_prompt.v2.md`. See [system-prompt.md](system-prompt.md)
for how to read the active prompt.

## Widget rendering

`renderTableInBubble` in `js/widget.js`:

1. Always renders a one-line summary (`<rows> rows from <tool>`).
2. If `rows.length > 0`, renders the table wrapper, the show/hide
   toggle, and the CSV download button.
3. If `provenance` is present, renders a "Show provenance" button that
   toggles a collapsed `<dl>` panel.

The provenance panel layout (`renderProvenancePanel`):

```
Executed     2026-04-29T15:42:11+00:00
Tool         query_datastore
Rows         1 returned, 1 total
Sanity       (only the set flags)
Query        { ... pretty-printed JSON ... }
```

CSS classes (`css/widget.css`):

- `.dkan-aiq-prov-panel` — light gray background container.
- `.dkan-aiq-prov-dl` — definition-list grid layout.
- `.dkan-aiq-prov-code` — dark JSON code block.

Override these from a theme to restyle without template overrides.

### Zero-row results

Before Phase 5, `renderTableInBubble` bailed silently on
`rows.length === 0`, hiding the very sanity flags Phase 4 was meant to
surface. Now: when rows are empty but provenance is present, the
summary line + "Show provenance" panel still render so the
`coverage_warning` (or other sanity flag) is visible. Table wrapper
and CSV button are skipped.

### Browser cache caveat

Provenance lives in the JS asset library. After a deploy that bumps
`widget.js`, hard-refresh the browser (or run `ddev drush cr` to bump
the library hash) — otherwise the old bundle silently keeps rendering
and the Show-provenance button appears to be missing.
