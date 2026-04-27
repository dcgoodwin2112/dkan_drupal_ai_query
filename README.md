# DKAN AI Query

Natural-language query widget for DKAN datasets. Built on `drupal/ai` + `drupal/ai_agents` + `dkan_query_tools`. Replaces `dkan_nl_query` with a polling-based architecture that aligns with the Drupal AI initiative's roadmap.

## Architecture

```
Browser
  │  POST /api/dkan-ai-query/start          ←  long-blocking, ~3-30s
  │  GET  /api/dkan-ai-query/poll/{tid}     ←  parallel, 500 ms cadence
  ▼
NlQueryController
  │
  ▼
ai_agents agent: dkan_data_query
  │
  ▼
ai.provider plugin (anthropic / openai)
  │
  ▼
FunctionCall tools: query_datastore, query_datastore_join,
  get_datastore_schema, get_datastore_stats, search_columns,
  search_datasets, list_datasets, list_distributions,
  find_dataset_resources, create_chart
  │
  ▼
dkan_query_tools service methods (DatastoreTools, MetastoreTools, SearchTools)
```

While `solve()` blocks server-side, `ai_agents` writes per-iteration status events into `PrivateTempStore` keyed by `thread_id`. The browser polls those events and renders progress + per-iteration text. Tables and chart specs are captured separately by `ArtifactCaptureSubscriber` and surfaced in the same poll response.

## Requirements

- Drupal 10.5+ or 11
- DKAN (`metastore`, `datastore` modules enabled)
- `dkan_query_tools` module enabled — provides the catalog/datastore/search tool classes that the FunctionCall plugins call into. See [dkan_query_tools README](../dkan_query_tools/README.md).
- `drupal/ai` ^1.2 with `drupal/ai_agents` ^1.2
- An AI provider module: `drupal/ai_provider_anthropic` and/or `drupal/ai_provider_openai`
- API key for the chosen provider

## Installing

1. Install the `dkan_query_tools` module first (see its README for the Composer config). In short:

   ```bash
   # Add the path repo or VCS entry to composer.json, then:
   composer update dcgoodwin2112/dkan_query_tools
   ddev drush en dkan_query_tools
   ```

2. Make sure the Drupal AI stack is in place:

   ```bash
   composer require drupal/ai drupal/ai_agents drupal/ai_provider_anthropic
   ddev drush en ai ai_agents ai_provider_anthropic
   ```

3. Enable this module:

   ```bash
   ddev drush en dkan_drupal_ai_query
   ```

The install hook:

1. Installs the `dkan_aiq_conversation` and `dkan_aiq_message` entity types.
2. Migrates `anthropic_api_key` / `openai_api_key` from `dkan_nl_query.settings` into the Key registry as `dkan_anthropic` / `dkan_openai` (skips any Key that already exists).
3. Logs a notice with a link to `/admin/config/system/keys`.

If you upgrade later (e.g. add new entity fields), run:

```bash
ddev drush updb
```

`hook_update_10002` (optional) copies existing `dkan_nl_query` conversations into the new entity types so user history carries over.

## Granting permissions

The install does NOT auto-grant permissions to existing roles (Drupal core does not do this for module enables). Run:

```bash
ddev drush role:perm:add authenticated 'use dkan drupal ai query'
ddev drush role:perm:add authenticated 'manage own dkan drupal ai query conversations'
ddev drush role:perm:add administrator 'administer dkan drupal ai query'
ddev drush role:perm:add administrator 'administer dkan drupal ai query conversations'
```

| Permission | Use |
|---|---|
| `use dkan drupal ai query` | Submit questions through the widget |
| `manage own dkan drupal ai query conversations` | Save / list / pin / delete one's own conversation history |
| `administer dkan drupal ai query` | Access the settings form at `/admin/config/dkan/ai-query` |
| `administer dkan drupal ai query conversations` | View and manage other users' conversations |

## Configuring

1. **API keys.** Visit `/admin/config/system/keys`. Confirm `dkan_anthropic` (and `dkan_openai` if used) exist and contain valid keys.
2. **Default model.** Visit `/admin/config/dkan/ai-query`. Pick a default model from the dropdown.
3. **Default provider.** Visit `/admin/config/ai/settings`. Select Anthropic or OpenAI as the default provider for the `chat` operation type. (drupal-ai 1.2 does not register `chat_with_tools` as a separate operation type; revisit when upgrading to a version that does.)
4. **Provider key wiring.** Visit `/admin/config/ai/providers/anthropic` (and/or `openai`). Set the `api_key` field to the Key entity (`dkan_anthropic` / `dkan_openai`).
5. **Widget toggles.** Back at `/admin/config/dkan/ai-query`, decide which UI features users see (model selector, examples, debug panel, history sidebar).

## Placing the widget

The widget is provided as a block (`DKAN AI Query Widget`, category DKAN).

### Single-dataset mode (recommended for dataset detail pages)

1. Place the block on a region of your front-end theme.
2. Set "Dataset UUID" to the target dataset's UUID.
3. Visibility: scope to dataset detail paths (e.g. `/dataset/*` or whatever your routing uses). For Olivero with dynamic dataset paths, consider a custom block placement per dataset, or a contextual placement via a custom block module.

### Cross-dataset mode

1. Place the block with "Dataset UUID" left blank.
2. The widget shows a dataset selector populated from the catalog and lets users pick — or omit a dataset and the agent will discover one via `find_dataset_resources` / `search_datasets`.

### Block visibility note

Drupal's Request Path block-visibility condition does **literal** path matching. `/user` does not match `/user/1`. Use newline-separated patterns with wildcards:

```
/dataset
/dataset/*
```

## Removing dkan_nl_query

After verifying that conversations and queries work end-to-end on the new module, disable the legacy module:

```bash
ddev drush pmu -y dkan_nl_query
```

The legacy entity tables remain in the database. To drop them as well, manually:

```bash
ddev drush sql:query "DROP TABLE nl_query_messages, nl_query_conversations;"
```

The migrated Key entities (`dkan_anthropic`, `dkan_openai`) are untouched by the legacy uninstall.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/dkan-ai-query/start` | Submit a question, blocking until `solve()` completes |
| GET | `/api/dkan-ai-query/poll/{thread_id}` | Read status events + artifacts captured so far |
| GET | `/api/dkan-ai-query/conversations` | List the current user's conversations |
| GET | `/api/dkan-ai-query/conversations/{id}` | Load a conversation with messages and artifacts |
| DELETE | `/api/dkan-ai-query/conversations/{id}` | Delete a conversation and its messages |
| POST | `/api/dkan-ai-query/conversations/{id}/pin` | Toggle the pinned flag |

`/start` request body:

```json
{
  "question": "...",
  "thread_id": "<unique per turn>",
  "resource_id": "abc123__1773329007",   // optional — pin to one resource
  "dataset_id": "uuid",                   // optional — pin to one dataset
  "conversation_id": 42,                  // optional — continue an existing thread
  "model": "anthropic__claude-haiku-4-5-20251001"  // optional
}
```

`/poll/{thread_id}` response body:

```json
{
  "thread_id": "...",
  "events": [{"type": "agent_started", ...}, ...],
  "artifacts": [{"type": "data", "rows": [...], ...}, {"type": "chart", "spec": {...}}, ...]
}
```

## Operational notes

- **PHP-FPM workers.** Each turn holds one worker for the full `solve()` duration. Tune `pm.max_children` for expected concurrency. Set `request_terminate_timeout` ≥ 120s to avoid mid-solve kills.
- **Web server timeouts.** Raise Nginx `proxy_read_timeout` / Apache `ProxyTimeout` to ≥ 120s on `/api/dkan-ai-query/start`. Disable response buffering on that path so long requests don't fill the buffer.
- **Polling cost.** `/poll` reads from `PrivateTempStore` (DB-backed). At 500 ms cadence × N concurrent conversations, that's ~120 reqs/min/user against the temp store. Manageable; raise the JS `POLL_INTERVAL_MS` to 750–1000 if it shows up in metrics.
- **No graceful cancellation.** Closing the browser tab does not abort `solve()`. Worth flagging in the UI when implementing real cancel; v1 is best-effort.

## Tests

PHPUnit:

```bash
cd web/modules/custom/dkan_drupal_ai_query && ../../../vendor/bin/phpunit
```

Lint (PHP only — JS sniffs in Drupal phpcs misfire on modern JS):

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module web/modules/custom/dkan_drupal_ai_query/
```

Phase 0–4 smoke tests are documented in the implementation plan at `[plans/please-research-the-drupal-atomic-gizmo.md]` (developer machine only).
