# DKAN AI Query

Natural-language query widget for DKAN datasets. Built on `drupal/ai` + `drupal/ai_agents` + `dkan_query_tools`. Polling-based architecture (long-blocking POST + 500 ms GET poll) aligned with the Drupal AI initiative's roadmap.

Project page: <https://www.drupal.org/project/dkan_ai_query>

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
  get_datastore_schema, get_data_dictionary, get_datastore_stats,
  search_columns, search_datasets, list_datasets, list_distributions,
  find_dataset_resources, sample_rows, distinct_values, compute_stats,
  create_chart, refuse, [query_datastore_raw — opt-in]
  │
  ▼
dkan_query_tools service methods (DatastoreTools, MetastoreTools, SearchTools)
```

While `solve()` blocks server-side, `ai_agents` writes per-iteration status events into `PrivateTempStore` keyed by `thread_id`. The browser polls those events and renders progress + per-iteration text. Tables and chart specs are captured separately by `ArtifactCaptureSubscriber` and surfaced in the same poll response.

## Requirements

- Drupal 10.5+ or 11.2+, PHP 8.3+
- DKAN 4.1+ (`dkan_metastore`, `dkan_datastore` modules enabled)
- `dkan_query_tools` module enabled — provides the catalog/datastore/search tool classes that the FunctionCall plugins call into. It ships as a submodule of the [dkan_mcp_server](https://www.drupal.org/project/dkan_mcp_server) project.
- `drupal/ai` ^1.3 with `drupal/ai_agents` ^1.2
- An AI provider module: `drupal/ai_provider_anthropic` and/or `drupal/ai_provider_openai` (suggested, not required by Composer — install one explicitly)
- API key for the chosen provider

## Versioning

This module uses 0.x semantic versioning while the API settles: **a minor bump
(0.1 → 0.2) may contain breaking changes**; patch releases are fixes only.
Composer's caret operator pins below 1.0 — `^0.1` will never pick up `0.2.0` —
so moving to a new minor is always a deliberate constraint bump. Check the
[release notes](https://www.drupal.org/project/dkan_ai_query) before bumping.

## Installing

1. Require this module together with the `dkan_query_tools` dependency chain.
   `dkan_query_tools` ships inside the
   [dkan_mcp_server](https://www.drupal.org/project/dkan_mcp_server) project:

   ```bash
   composer require drupal/dkan_ai_query:^0.1 drupal/dkan_mcp_server:^1.0@alpha drupal/dkan_query_tools:^1.0@alpha drupal/mcp_server:2.x-dev
   ```

   The explicit constraints matter: this module depends on alpha/dev packages
   (`drupal/dkan_mcp_server`, `drupal/dkan_query_tools`,
   `drupal/mcp_server 2.x-dev`), which a default `minimum-stability: stable`
   root won't accept otherwise.

2. Make sure the Drupal AI stack is in place, including an AI provider module
   (this module suggests but does not require one):

   ```bash
   composer require drupal/ai drupal/ai_agents drupal/ai_provider_anthropic
   ddev drush en ai ai_agents ai_provider_anthropic
   ```

3. Enable this module (`dkan_query_tools` is enabled with it as a dependency;
   the MCP server module itself stays off unless you enable it):

   ```bash
   ddev drush en dkan_ai_query
   ```

The install hook installs the `dkan_aiq_conversation` and `dkan_aiq_message` entity types. Configure API keys at `/admin/config/system/keys` (see Configuring below).

If you upgrade later (e.g. add new entity fields), run:

```bash
ddev drush updb
```

## Granting permissions

The install does NOT auto-grant permissions to existing roles (Drupal core does not do this for module enables). Run:

```bash
ddev drush role:perm:add authenticated 'use dkan ai query'
ddev drush role:perm:add authenticated 'manage own dkan ai query conversations'
ddev drush role:perm:add administrator 'administer dkan ai query'
ddev drush role:perm:add administrator 'administer dkan ai query conversations'
```

| Permission | Use |
|---|---|
| `use dkan ai query` | Submit questions through the widget |
| `manage own dkan ai query conversations` | Save / list / pin / delete one's own conversation history |
| `administer dkan ai query` | Access the settings form at `/admin/dkan/ai-query/settings` |
| `administer dkan ai query conversations` | View and manage other users' conversations |

## Configuring

The module's admin pages are grouped under `/admin/dkan/ai-query` (alongside the rest of DKAN's admin landing page), with two children: **Settings** (`/admin/dkan/ai-query/settings`) and **Dataset caveats** (`/admin/dkan/ai-query/caveats`).

1. **API keys.** Visit `/admin/config/system/keys`. Confirm `dkan_anthropic` (and `dkan_openai` if used) exist and contain valid keys.
2. **Default model.** Visit `/admin/dkan/ai-query/settings`. Pick a default model from the dropdown.
3. **Default provider.** Visit `/admin/config/ai/settings`. Select Anthropic or OpenAI as the default provider for the `chat` operation type. (drupal-ai 1.2 does not register `chat_with_tools` as a separate operation type; revisit when upgrading to a version that does.)
4. **Provider key wiring.** Visit `/admin/config/ai/providers/anthropic` (and/or `openai`). Set the `api_key` field to the Key entity (`dkan_anthropic` / `dkan_openai`).
5. **Widget toggles.** Back at `/admin/dkan/ai-query/settings`, walk the grouped sections (Widget interface, Result actions, Conversation history, Agent runtime, Logging) to decide which features users see and tune runtime/retention/debug knobs.
6. **Raw query escape hatch (optional).** Under **Agent runtime**, the **Enable raw passthrough query tool** checkbox registers `query_datastore_raw` on the agent. This lets the agent submit a verbatim DKAN DatastoreQuery payload (nested OR groups, three-way joins, compound expressions) when the flat `query_datastore` tools cannot express the shape. The flat tools remain the default per the system prompt — raw is documented as the escape hatch. Off by default; turn on after telemetry confirms behavior.

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

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/dkan-ai-query/start` | Submit a question, blocking until `solve()` completes |
| GET | `/api/dkan-ai-query/poll/{thread_id}` | Read status events + artifacts captured so far |
| GET | `/api/dkan-ai-query/conversations?offset=&limit=` | Paginated list of the current user's conversations. Defaults `offset=0`, `limit=25` (capped 1–100). Response: `{items, total, offset, limit}` |
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
- **Conversation retention.** Set the **Conversation history → Retention (days)** setting to a non-zero value to have `hook_cron` purge unpinned conversations older than that age. Default `0` keeps everything forever — relevant for PII / compliance reviews.
- **Model dropdown denylist.** The widget's model selector is fed by `AiProviderPluginManager::getSimpleProviderModelOptions('chat', FALSE)`, which on the OpenAI provider returns every model regardless of capability. `QueryWidgetBlock::build()` filters out models whose id matches `/(image|audio|tts|transcribe|realtime|deep-research|search-preview|search-api)/i` so users don't see image / audio / realtime / deep-research variants in a chat dropdown. If a new non-chat OpenAI model family ships, extend the regex in [src/Plugin/Block/QueryWidgetBlock.php](src/Plugin/Block/QueryWidgetBlock.php).
- **Catalog pre-seeding.** `CatalogContextBuilder` injects an `[uuid → title]` block into every `/start` task text (1h cache, capped at 50 entries). The agent uses titles in prose without a `list_datasets` round trip and matches user references to the right UUID without `find_dataset_resources` for known catalogs. Falls back gracefully when the metastore is unreachable.

## Stats vs queries (compute_stats)

The datastore DSL handles `sum`, `count`, `avg`, `min`, `max` on the full table via aggregate expressions on `query_datastore`. Anything beyond that — **median, percentile, stddev, variance, quartiles, correlation** — must go through the `compute_stats` tool, which runs on rows the agent has already fetched.

Two important guardrails (encoded in the system prompt):

- The agent must NOT eyeball median/percentile from a `query_datastore` row dump; even small tables go through `compute_stats` so the answer is reproducible.
- `compute_stats` runs on whatever the agent fetched. If `query_datastore` returned the row-cap (default 500, up to 5000 for charting), `compute_stats` is computing on a sample, not the population — the tool's `warnings` array calls this out.

Charts (`create_chart`) may raise `query_datastore`'s `limit` up to 5000 for long time series; keep `limit ≤ 500` for browsing and ad-hoc questions. The 500/5000 split exists so charts don't silently truncate.

## Bumping the system prompt

The agent's system prompt is file-backed at `prompts/query_system_prompt.v{N}.md` and resolved through `SystemPromptLoader`. Versioning is intentional so eval runs and audit tooling can correlate behavior with prompt changes (every persisted message records its `prompt_version`).

To roll a new version:

1. Copy the current prompt: `cp prompts/query_system_prompt.v6.md prompts/query_system_prompt.v7.md`
2. Edit the new file.
3. Bump `SystemPromptLoader::DEFAULT_VERSION` (in [src/Service/SystemPromptLoader.php](src/Service/SystemPromptLoader.php)) and the matching test default in [tests/src/Unit/Service/SystemPromptLoaderTest.php](tests/src/Unit/Service/SystemPromptLoaderTest.php).
4. Sync the file content into the agent config entity: `ddev drush dkan-aiq:sync-prompt`.
5. Clear cache: `ddev drush cr`.
6. Run the eval suite to spot routing regressions: `ddev drush dkan-aiq:eval`.

Live ad-hoc overrides for evaluation use `SystemPromptLoader::setOverride('vN')` and don't require a code change. See [docs/system-prompt.md](docs/system-prompt.md) for details.

## Caveats vs data dictionaries

Two parallel metadata systems are merged into the agent's view of a dataset:

- **Data dictionaries** describe *static* per-column metadata (declared type, human title, description) authored by the dataset publisher and stored in the metastore as `data-dictionary` items. Linked from a distribution via `describedBy`. Surfaced via `get_datastore_schema` (per-column `dictionary_*` keys + `dictionary_url` at the root) and the dedicated `get_data_dictionary` tool.
- **Caveats** (`DatasetCaveatRegistry`) describe *operational* concerns the agent must honor when querying — suppression rules, coverage windows, code-list interpretations. These live in config entities maintained by the site operator and are merged into `get_datastore_schema` and `find_dataset_resources` responses as `caveat` / `dataset_caveats` keys.

When both exist, both apply: dictionaries explain what a column means; caveats explain how to read or filter it. The system prompt instructs the agent to prefer dictionary text over column-name guesses, and to call out storage-vs-dictionary type disagreements when relevant.

## Documentation

Topic guides live in [docs/](docs/):

| Doc | Covers |
|---|---|
| [accuracy-overview.md](docs/accuracy-overview.md) | One-page map of the 5 accuracy subsystems and how a request flows through them. Start here. |
| [eval-harness.md](docs/eval-harness.md) | `drush dkan-aiq:eval`, golden case format, baseline + per-phase deltas. |
| [dataset-caveats.md](docs/dataset-caveats.md) | The `dataset_caveat` config entity and its admin UI. |
| [system-prompt.md](docs/system-prompt.md) | How the file-backed, versioned system prompt works and how to bump it. |
| [refusal-flow.md](docs/refusal-flow.md) | `RefuseTool`, the 3-strikes unknown-column guard, and how refusals render. |
| [provenance.md](docs/provenance.md) | The `provenance` block and the widget's provenance panel. |
| [changelog.md](docs/changelog.md) | Eval pass-rate delta per phase. |

Upstream tool-response contract (success / error / sanity-flag shapes
returned by `DatastoreTools`) lives at
[dkan_query_tools/docs/tool-responses.md](https://git.drupalcode.org/project/dkan_mcp_server/-/blob/1.0.x/modules/dkan_query_tools/docs/tool-responses.md).

## Tests

Both unit suites are standalone (stub-based, no Drupal bootstrap). From the
module directory, using any PHPUnit 10/11 (e.g. a site's `vendor/bin/phpunit`
or the module's own after `composer install`):

```bash
phpunit                                              # main suite
phpunit -c modules/dkan_ai_query_mock/phpunit.xml    # mock submodule suite
```

Lint and static analysis use the bundled configs (`phpcs.xml.dist` is PHP/YAML
only — JS and CSS are covered by core's eslint/stylelint in CI):

```bash
phpcs                          # Drupal + DrupalPractice
phpstan analyse                # level 1
```
