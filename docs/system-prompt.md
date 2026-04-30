# System prompt

The `dkan_data_query` agent's system prompt lives in versioned markdown
files at `prompts/query_system_prompt.v{N}.md`, then is synced into the
`ai_agents.ai_agent.dkan_data_query` config entity at install / update
time. The admin UI at `/admin/config/ai/agents/dkan_data_query` and the
prompt the LLM actually receives stay in lockstep.

## File layout

```
prompts/
  query_system_prompt.v1.md    # Phase 3 baseline
  query_system_prompt.v2.md    # Phase 4 added sanity-flag rules
  query_system_prompt.v3.md
  query_system_prompt.v4.md    # current
```

`SystemPromptLoader::DEFAULT_VERSION` selects the active file.

## Runtime mechanism

1. `dkan_drupal_ai_query_install()` writes the active `.md` body into the
   agent config entity's `system_prompt` field via `AgentPromptSync`.
2. Each prompt-version bump ships a new `hook_update_N` that re-runs
   the same sync after the constant flips, keeping existing sites in
   sync on `drush updb`.
3. At request time, `Drupal\ai_agents\AiAgentEntityWrapper` reads
   `system_prompt` straight from the config entity and dispatches
   `BuildSystemPromptEvent`. We don't subscribe to that event today; if
   we ever need *runtime augmentation* (e.g. selected-dataset context),
   it's still the right seam — but it should append, not replace.
4. The admin "override" link in the agent form continues to work as the
   `drupal/ai_agents` framework intends. An admin override wins until
   the next sync, at which point `drush updb` / `drush dkan-aiq:sync-prompt`
   will overwrite it. Run `drush cex` first if you want to keep an
   admin override across deploys.

## Bumping to a new version

```bash
cp prompts/query_system_prompt.v4.md prompts/query_system_prompt.v5.md
# edit v5 with the new rules
```

Then in `src/Service/SystemPromptLoader.php`:

```php
public const DEFAULT_VERSION = 'v5';
```

Add a new update hook in `dkan_drupal_ai_query.install`:

```php
function dkan_drupal_ai_query_update_10007(): string {
  return (string) \Drupal::service('dkan_drupal_ai_query.agent_prompt_sync')->sync()['message'];
}
```

Run the eval and commit the new prompt file, the loader change, the
update hook, and the eval JSONL together. The eval output's
`prompt_version` field shows v4 → v5 in the diff so reviewers can see
exactly what behavior shifted.

Do not delete old prompt versions. They cost nothing on disk and let
operators roll back via `drush dkan-aiq:sync-prompt --version=v4`.

## Why ship as `.md` and sync (rather than embedding in YAML)

- **Diff hygiene.** Prompt edits are plain English with no escaping,
  not multi-line YAML literals.
- **Versioning.** Each prompt revision lands as a new file (v1, v2, …),
  so old eval JSONLs stay reproducible against their original prompt
  by passing `--prompt-version=vN` to the eval harness or
  `drush dkan-aiq:sync-prompt --version=vN` to a dev site.
- **Admin honesty.** The config entity holds the real prompt, so the
  admin UI shows what the LLM receives. The `hook_form_alter` on the
  agent edit form points operators at the source-of-truth file.

## Prompt version provenance

`prompt_version` flows through the system as follows:

1. `SystemPromptLoader::activeVersion()` returns the constant.
2. `NlQueryController::start()` appends a meta artifact:
   `{type: meta, prompt_version: 'v4'}` to `ArtifactStorage` per thread.
3. The artifact persists with every assistant message via the
   conversation save hook on `Message.artifacts` (JSON column, no
   schema migration).
4. `EvalRunner` reads the loader directly and stamps `prompt_version`
   on each `CaseResult` JSONL row.
5. The widget reads the meta artifact alongside the data and refusal
   artifacts; it currently does not surface the version, but a future
   "show debug info" toggle could.

## Compatibility notes

- **Upstream contract.** We no longer depend on `BuildSystemPromptEvent`
  for replacement, so `drupal/ai_agents` upgrades that touch that event
  no longer affect us. The `ai_agent` config entity's `system_prompt`
  property is the only contract we lean on.
- **Cache.** `drush cr` is needed after a prompt sync only if you want
  the new prompt to take effect immediately on already-rendered pages;
  the next agent invocation reads from the freshly-saved config entity
  regardless. Restart PHP-FPM if you hit a stale opcache; that's a
  PHP-level concern, not a Drupal one.
