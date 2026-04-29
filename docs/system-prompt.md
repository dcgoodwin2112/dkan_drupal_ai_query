# System prompt

The `dkan_data_query` agent's system prompt lives in versioned markdown
files at `prompts/query_system_prompt.v{N}.md`, not in the agent YAML.
Editing the prompt is the highest-leverage accuracy change you can make,
so it gets diffable git history separate from the agent config.

## File layout

```
prompts/
  query_system_prompt.v1.md    # Phase 3 baseline
  query_system_prompt.v2.md    # current — Phase 4 added sanity-flag rules
```

`SystemPromptLoader::DEFAULT_VERSION` selects the active file. Bumping
to `v3` is one constant change plus a new file.

## Runtime mechanism

The agent YAML at
`config/install/ai_agents.ai_agent.dkan_data_query.yml` still has a
prompt body for fallback. At runtime, `SystemPromptSubscriber` listens
for `BuildSystemPromptEvent` (event name `ai_agents.pre_system_prompt`),
dispatched by `Drupal\ai_agents\AiAgentEntityWrapper::determineSolvability()`
before each agent invocation. The subscriber:

1. Checks the event's agent id matches `dkan_data_query`. Other agents
   pass through untouched.
2. Calls `SystemPromptLoader::load()` for the active version.
3. If the file exists, calls `$event->setSystemPrompt($body)`.
4. If the file is missing or empty, logs a warning and falls back to
   the YAML-resident prompt.

No fork of `drupal/ai_agents` is required. The event API is stable
through 1.3.x and is on the roadmap for 2.x.

## Why not edit the YAML directly

- **Diff hygiene.** Prompt edits churn quoted multi-line YAML; the
  markdown file is plain English with no escaping.
- **Config sync friendliness.** Prompt edits don't generate config
  diffs that need to ship through `drush config:export`.
- **Versioning.** Each prompt revision lands as a new file (v1, v2, …),
  so old eval JSONLs stay reproducible against their original prompt.

## Bumping to a new version

```bash
cp prompts/query_system_prompt.v2.md prompts/query_system_prompt.v3.md
# edit v3 with the new rules
```

Then in `src/Service/SystemPromptLoader.php`:

```php
public const DEFAULT_VERSION = 'v3';
```

Run the eval and commit the new prompt file, the loader change, and the
eval JSONL together. The eval output's `prompt_version` field shows
v2 → v3 in the diff so reviewers can see exactly what behavior shifted.

Do not delete old prompt versions. They cost nothing on disk and let
operators roll back by changing the constant alone.

## Prompt version provenance

`prompt_version` flows through the system as follows:

1. `SystemPromptLoader::activeVersion()` returns the constant.
2. `NlQueryController::start()` appends a meta artifact:
   `{type: meta, prompt_version: 'v2'}` to `ArtifactStorage` per thread.
3. The artifact persists with every assistant message via the
   conversation save hook on `Message.artifacts` (JSON column, no
   schema migration).
4. `EvalRunner` reads the loader directly and stamps `prompt_version`
   on each `CaseResult` JSONL row.
5. The widget reads the meta artifact alongside the data and refusal
   artifacts; it currently does not surface the version, but a future
   "show debug info" toggle could.

## Compatibility notes

- **Upstream contract.** `BuildSystemPromptEvent` is the one external
  surface this depends on. Verify it still exists when bumping
  `drupal/ai_agents` past 1.3.x — `grep` in
  `web/modules/contrib/ai_agents/src/Event/` after the upgrade. If the
  contract changes, the fallback path (YAML-resident prompt) keeps
  working but loses versioning until the subscriber is updated.
- **Cache.** No drush cache rebuild is needed when editing a prompt
  file; the loader reads from disk per request and per-instance caches
  the result. Restart PHP-FPM if you hit a stale opcache; that's a
  PHP-level concern, not a Drupal one.
