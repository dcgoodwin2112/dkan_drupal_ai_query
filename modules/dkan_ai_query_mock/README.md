# DKAN AI Query Mock

Scenario-driven mock AI provider for browser-driven testing of
`dkan_ai_query` without hitting a live LLM.

**Development/testing only.** Do not enable on a production site: it
registers a fake AI provider whose answers come from scripted scenarios,
not a model.

Real tool execution against the datastore continues unchanged — only the LLM
is mocked. Drop in scripted YAML scenarios under `scenarios/` to exercise
specific UI states (data tables, charts, refusals, multi-turn).

## Quick start

```bash
ddev drush en dkan_ai_query_mock
ddev drush dkan-aiq-mock:fixture:install
```

The first command enables the submodule. The second seeds a 30-park / 7-year
National Parks visitation fixture so all shipped scenarios have data to query
on a fresh DKAN site. See `fixtures/README.md` for column shape and
provenance.

Then either:

1. Set the chat default to mock site-wide (admin UI):
   `/admin/config/ai/settings` → Default chat provider →
   `DKAN AI Query Mock - Scripted scenario`.

2. Or via drush:

   ```bash
   ddev drush php:eval '
   \Drupal::service("config.factory")
     ->getEditable("ai.settings")
     ->set("default_providers.chat", ["provider_id" => "dkan_aiq_mock", "model_id" => "scripted"])
     ->save();
   '
   ```

The widget at `/ai-query` (or wherever the block is placed) will now
replay scripted scenarios instead of calling Anthropic / OpenAI.

On install the submodule auto-appends `dkan_aiq_mock__scripted` to
`dkan_ai_query.settings:allowed_models` if a curated list exists, so
the mock model shows up in the widget's model dropdown. Uninstall removes
it again.

## Selecting a scenario

`ScenarioMatcher` resolves the active scenario per chat call, in this order:

1. `X-DKAN-Aiq-Scenario` request header
2. `dkan_aiq_scenario` cookie
3. State key `dkan_ai_query_mock.active_scenario` (set via the picker
   form at `/admin/dkan/ai-query/mock-scenarios`)
4. First scenario whose `match.question_contains` substrings all appear in
   the user's typed question
5. None matched → mock emits a debug-friendly stub turn listing the
   available scenarios

## Scenario YAML format

Files live in `scenarios/*.yml`. Each is a sequence of turns the mock
provider replays in order:

```yaml
id: list_datasets_then_query
description: Find the parks dataset, query the busiest in 2023, answer top-5.
match:
  question_contains: [parks, 2023]
turns:
  - type: tool_calls
    calls:
      - name: search_datasets
        args: { keyword: parks }
  - type: tool_calls
    calls:
      - name: query_datastore
        args:
          resource_id: '${FIXTURE_RESOURCE_ID}'
          conditions: '[{"property":"year","value":"2023","operator":"="}]'
          sort_field: recreation_visits
          sort_direction: desc
          limit: 5
  - type: final_answer
    content: |
      The five most-visited U.S. National Parks in 2023 were Great Smoky
      Mountains, Grand Canyon, Zion, Yellowstone, and Rocky Mountain.
```

Tools are referenced by `function_name` (the value on the FunctionCall plugin
attribute), not plugin id. Scenarios must end with a `final_answer` turn
within `max_loops - 1` tool turns or the agent returns `JOB_NOT_SOLVABLE`.

`${FIXTURE_RESOURCE_ID}` is substituted at chat-call time from state key
`dkan_ai_query_mock.fixture_resource_id`, written by
`drush dkan-aiq-mock:fixture:install`. Use this rather than a hardcoded
`{identifier}__{version}` so scenarios stay portable across DKAN sites.

## Tunable knobs

| State key | Default | Purpose |
|---|---|---|
| `dkan_ai_query_mock.active_scenario` | unset | Force a specific scenario site-wide. |
| `dkan_ai_query_mock.force_active` | unset | Engage the emergency `PreGenerateResponseEvent` kill-switch (intercepts every chat call regardless of provider). |
| `dkan_ai_query_mock.turn_delay_ms` | 600 | Per-turn `usleep` to simulate LLM latency. The widget polls every 500 ms; without a deliberate stall, sub-second mock runs complete before any poll fires and the live UI doesn't show artifacts in real time. Set to 0 to disable. |

Environment variable `DKAN_AIQ_FORCE_MOCK=1` is equivalent to setting
`force_active` (useful for staging environments).

## Fixture dataset

The submodule ships a **National Parks Visitation** fixture (30 parks × 7
years, 210 rows) so the scripted scenarios work on any DKAN site, including
fresh installs with no datasets. Real datastore queries from the FunctionCall
plugins continue to execute against this fixture.

```bash
ddev drush dkan-aiq-mock:fixture:install   # register, harvest, drain queues
ddev drush dkan-aiq-mock:fixture:status    # show resolved resource_id
ddev drush dkan-aiq-mock:fixture:remove    # revert + drop datastore table
```

The fixture is stored in `fixtures/files/national_parks_visitation.csv` and
exposed as DKAN dataset identifier `a1b2c3d4-e5f6-4789-a0b1-c2d3e4f50001`.

DKAN resource versions are Unix timestamps set at first import, so the full
`{identifier}__{version}` resource id is install-specific. Scripted scenarios
reference `${FIXTURE_RESOURCE_ID}`; the mock provider substitutes the resolved
id from state key `dkan_ai_query_mock.fixture_resource_id` at chat-call
time. `FixtureLoader::install()` writes that value once the harvest finishes.

## Tests

```bash
cd docroot/modules/custom/dkan_ai_query/modules/dkan_ai_query_mock
../../../../../../vendor/bin/phpunit
```

Unit tests cover YAML parsing, scenario validation, and matcher precedence.
