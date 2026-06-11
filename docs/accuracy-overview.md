# Accuracy architecture

This module shipped a five-phase accuracy effort that turned a baseline
40% pass rate on a 10-case golden set into a 100% pass rate while making
every answer auditable. This page is the orientation map; topic pages
linked below cover each subsystem in depth.

## What an answer is guaranteed to be

After the accuracy effort, an answer that reaches the user has these
properties:

- **Grounded.** The agent calls schema / sample / distinct-values tools
  before issuing a structured query. Column references the schema does
  not contain are caught as `unknown_column` and self-corrected, or the
  agent refuses after three strikes.
- **Read-only.** Every datastore query goes through the
  `DatastoreQuery` DSL — no raw SQL is exposed. A read-only MariaDB role
  is documented for sites that want defense-in-depth at the connection
  layer.
- **Auditable.** Every successful query carries a `provenance` block
  (`executed_at`, `tool`, `row_count`, `total_rows`, `sanity_flags`,
  `query_summary`). The widget renders this as a collapsible panel.
- **Honestly refused when it should be.** Refusals are structured
  (`RefuseTool` with a fixed `reason_category` enum), not free-text.
  Eval scoring and UI cards both read the structured payload.
- **Acknowledges sanity flags.** When a query returns zero rows, hits
  the row cap, or filters out of coverage, the prompt requires the
  agent to call this out in the final answer.

## Subsystems and where they live

| Subsystem | Entry point | Doc |
|---|---|---|
| Eval harness | `drush dkan-aiq:eval` | [eval-harness.md](eval-harness.md) |
| Dataset caveats | `/admin/dkan/ai-query/caveats` | [dataset-caveats.md](dataset-caveats.md) |
| Versioned system prompt | `prompts/query_system_prompt.v{N}.md` | [system-prompt.md](system-prompt.md) |
| Refusal flow + 3-strikes guard | `RefuseTool`, `UnknownColumnGuardSubscriber` | [refusal-flow.md](refusal-flow.md) |
| Provenance + sanity flags | `ArtifactCaptureSubscriber`, widget JS | [provenance.md](provenance.md) |
| Per-phase eval delta | `tests/eval/{phase}/run-*.md` | [changelog.md](changelog.md) |

The query-tool response shape (success, error, sanity flags) is owned
by the upstream module and documented at
[dkan_query_tools/docs/tool-responses.md](https://git.drupalcode.org/project/dkan_mcp_server/-/blob/1.0.x/modules/dkan_query_tools/docs/tool-responses.md).

## How a single answer flows through the stack

```
Browser
  │  POST /api/dkan-ai-query/start    (long-blocking)
  │  GET  /api/dkan-ai-query/poll/{tid}
  ▼
NlQueryController
  │
  ▼
ai_agents agent: dkan_data_query
  │  ↑ system prompt sourced from prompts/query_system_prompt.v{N}.md
  │    and synced into the agent config entity by hook_install /
  │    hook_update_N (drush dkan-aiq:sync-prompt for ad-hoc resync)
  ▼
LLM provider (Anthropic / OpenAI)
  │  picks tool calls per iteration
  ▼
FunctionCall plugins (query_datastore, get_datastore_schema, …, refuse)
  │
  │  AgentToolFinishedExecutionEvent fires after each tool, observed by:
  │   • UnknownColumnGuardSubscriber (priority 10) — bumps counter,
  │     trips a refusal at 3 unknown_columns
  │   • ArtifactCaptureSubscriber (priority 0) — captures rows + chart
  │     spec + provenance into ArtifactStorage
  ▼
dkan_query_tools service methods (DatastoreTools, MetastoreTools, SearchTools)
  │
  ▼
DKAN datastore (default DB connection, or read-only role if configured)
```

The poll endpoint returns the same artifacts the widget renders as
tables, charts, refusal cards, and provenance panels.

## Optional and conditional next phases

Phases 6 (LLM-as-judge) and 7 (raw SQL escape hatch) are not built. They
are sketched in the original planning doc (see "Project history" below)
with explicit triggers; neither trigger is currently met.

## Project history

The site-root [`dkan-ai-query-accuracy-plan.md`](../../../../../dkan-ai-query-accuracy-plan.md)
is the planning artifact for this effort. It tracks every phase's goal,
deviations from plan, and per-phase outcomes. It is project history, not
operator reference — the docs in this folder are the living
documentation.
