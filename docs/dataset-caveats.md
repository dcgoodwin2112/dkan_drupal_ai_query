# Dataset caveats

A `dataset_caveat` config entity per DKAN dataset, surfacing the things
the LLM cannot infer from schema alone:

- **Suppression rules.** Markers like `*`, `<11`, `N/A` and the policy
  behind them (HIPAA Safe Harbor, etc.).
- **Column-level caveats.** Free-text guidance attached to one column
  ("rate per 100,000 population, not per capita").
- **Freshness windows.** Last-updated and coverage date the model should
  cite when the user asks "how recent is this?".
- **Code lists.** Reference enumerations the model should validate
  filter values against.

These get injected into the output of `find_dataset_resources` and
`get_datastore_schema` (see "How caveats reach the model" below) so the
agent sees them at the same time it sees the schema, before issuing any
query.

## Why a config entity (not YAML, not metastore)

The original Phase 2 plan called for a static
`config/dataset_caveats.yml` file. We replaced it with a
`dataset_caveat` config entity for three reasons:

1. **A public module cannot ship UUID-keyed YAML.** Caveats are per-site
   curation, not module-distributed content.
2. **Site editors get an admin UI for free.** Drupal core's
   `DefaultHtmlRouteProvider` generates the CRUD routes; we just provide
   the form.
3. **Config sync travels caveats correctly.** `drush config:export`
   produces one `dkan_drupal_ai_query.caveat.<id>.yml` file per record;
   commit per environment as you would any other config entity.

Extending DKAN's metastore JSON Schema was rejected outright — the
schema is fixed (no `additionalProperties` extension point), and tying
caveat lifecycle to dataset CRUD would entangle re-imports.

## Admin UI

1. Grant the permission:

   ```bash
   ddev drush role:perm:add administrator 'administer dkan dataset caveats'
   ```

2. Navigate to `/admin/config/dkan/ai-query/caveats`. Empty list on a
   fresh install.

3. Click "Add dataset caveat":
   - **Dataset.** Select the target DKAN dataset. Locks after save (the
     UUID is the join key).
   - **Suppression.** Free-text note, one or two sentences. Used by the
     prompt to explain suppressed cells instead of treating them as
     numbers.
   - **Column caveats.** YAML map: `column_name: "guidance text"`.
   - **Freshness.** `updated:` and `coverage:` text fields.
   - **Code lists.** YAML map: `column_name: ["allowed", "values"]`.

4. Save. The cache invalidates automatically; the next agent invocation
   sees the new caveats.

## Storage shape

A saved entity exports as:

```yaml
id: d460252e_d42c_474a_9ea9_5287b1d595f6   # slugged UUID
label: "Crime in U.S. (FBI UCR, 2013)"      # denormalized at save
dataset_uuid: d460252e-d42c-474a-9ea9-5287b1d595f6
suppression: "Cells suppressed when value < 11 per FBI policy."
column_caveats:
  rate_per_100k: "Per 100,000 population. Not per capita."
freshness:
  updated: "2014-01-01"
  coverage: "2013 reporting year"
code_lists:
  state: ["AL", "AK", "AZ", "..."]
```

The machine `id` is `str_replace('-', '_', $uuid)` because config entity
ids only allow `[a-z0-9_]+`. The original UUID lives in `dataset_uuid`
and is what `DatasetCaveatRegistry` keys lookups by.

## How caveats reach the model

Two FunctionCall plugins inject caveats into their output. Both call
`DatasetCaveatRegistry` at execute time; no caveats means no extra keys
in the response.

### `get_datastore_schema`

For each schema column the agent sees, an inline `caveat` field is
attached when one is registered:

```json
{
  "fields": [
    {"name": "rate_per_100k", "type": "number",
     "caveat": "Per 100,000 population. Not per capita."}
  ],
  "dataset_caveats": {
    "suppression": "Cells suppressed when value < 11 per FBI policy.",
    "freshness": {"updated": "2014-01-01", "coverage": "2013 reporting year"},
    "code_lists": {"state": ["AL", "AK", "..."]}
  }
}
```

`column_caveats` are inlined per-field; the rest land under
`dataset_caveats`.

### `find_dataset_resources`

When the agent searches for a dataset by topic, each candidate result
gains a top-level `caveats` block carrying the same payload as
`getCaveats()` returns. This lets the agent factor suppression / freshness
into its dataset choice, not just its column choice.

## Service API

```php
$registry = \Drupal::service('dkan_drupal_ai_query.dataset_caveat_registry');

$registry->getCaveats($datasetUuid);          // full block, NULL if no record
$registry->getColumnCaveats($datasetUuid);    // ['col' => 'text', ...]
$registry->listDatasets();                    // string[] of UUIDs with records
$registry->resetCache();                      // force reload (admin save hook)
```

The registry caches the full result set on first read for the request.
A saved or deleted caveat triggers `resetCache()` via the entity's
form, so the next request sees fresh data.

## Operational notes

- **Empty caveat record.** A saved entity with all fields blank returns
  `[]`, not `NULL`, from `getCaveats()`. Callers can distinguish "no
  record" from "record exists but author left it blank".
- **Config sync caveats** (no pun intended). Treat caveat config files
  the same as any other content-aware config: usually committed to a
  per-environment overlay rather than the install profile, since the
  set of datasets differs between dev / staging / prod.
- **No retroactive validation.** Caveats are author-controlled
  free-text; the system does not check that the named columns actually
  exist on the dataset. A typo means the column caveat silently never
  attaches.
