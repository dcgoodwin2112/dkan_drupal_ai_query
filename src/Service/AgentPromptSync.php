<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Writes the active markdown system prompt into the dkan_data_query agent.
 *
 * The .md files under prompts/ are the editable source of truth. This service
 * is the bridge that copies the active version into the ai_agent config entity
 * so the admin UI at /admin/config/ai/agents reflects the prompt actually sent
 * to the LLM. Called from hook_install, hook_update_N, and the
 * dkan-aiq:sync-prompt Drush command.
 */
class AgentPromptSync {

  public const AGENT_ID = 'dkan_data_query';

  /**
   * Plugin id of the optional raw passthrough tool.
   */
  public const RAW_TOOL_ID = 'dkan_ai_query:query_datastore_raw';

  public function __construct(
    protected SystemPromptLoader $loader,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Toggle the raw passthrough tool on the dkan_data_query agent.
   *
   * The plugin class always exists; whether the agent advertises it to the
   * LLM is governed by the `enable_raw_query_tool` setting (default off).
   * This is the bridge that copies that setting onto the agent config entity.
   * Idempotent: a no-op when the agent's tools list already matches.
   *
   * @return array
   *   Structured result keyed by status, enabled, message.
   */
  public function syncTools(bool $enableRawTool): array {
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    $agent = $storage->load(self::AGENT_ID);
    if ($agent === NULL) {
      return [
        'status' => 'missing-agent',
        'enabled' => $enableRawTool,
        'message' => sprintf('ai_agent "%s" not found; install dkan_ai_query first.', self::AGENT_ID),
      ];
    }
    $tools = (array) $agent->get('tools');
    $current = !empty($tools[self::RAW_TOOL_ID]);
    if ($current === $enableRawTool && array_key_exists(self::RAW_TOOL_ID, $tools) === $enableRawTool) {
      return [
        'status' => 'unchanged',
        'enabled' => $enableRawTool,
        'message' => sprintf('Raw tool already %s on agent %s.', $enableRawTool ? 'enabled' : 'disabled', self::AGENT_ID),
      ];
    }
    if ($enableRawTool) {
      $tools[self::RAW_TOOL_ID] = TRUE;
    }
    else {
      unset($tools[self::RAW_TOOL_ID]);
    }
    $agent->set('tools', $tools)->save();
    return [
      'status' => 'synced',
      'enabled' => $enableRawTool,
      'message' => sprintf('Raw tool %s on agent %s.', $enableRawTool ? 'registered' : 'removed', self::AGENT_ID),
    ];
  }

  /**
   * Sync the given (or default) prompt version into the agent config entity.
   *
   * Returns a structured result so callers can log or surface it. Idempotent:
   * a no-op when the agent's prompt already matches the file.
   *
   * @return array
   *   Structured result keyed by status, version, message.
   */
  public function sync(?string $version = NULL): array {
    $version = $version === NULL || $version === '' ? $this->loader->activeVersion() : $version;
    $body = $this->loader->load($version);
    if ($body === NULL || $body === '') {
      return [
        'status' => 'missing-file',
        'version' => $version,
        'message' => sprintf('No prompt file found for version %s.', $version),
      ];
    }
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    $agent = $storage->load(self::AGENT_ID);
    if ($agent === NULL) {
      return [
        'status' => 'missing-agent',
        'version' => $version,
        'message' => sprintf('ai_agent "%s" not found; install dkan_ai_query first.', self::AGENT_ID),
      ];
    }
    if ((string) $agent->get('system_prompt') === $body) {
      return [
        'status' => 'unchanged',
        'version' => $version,
        'message' => sprintf('Agent %s already on prompt %s.', self::AGENT_ID, $version),
      ];
    }
    $agent->set('system_prompt', $body)->save();
    return [
      'status' => 'synced',
      'version' => $version,
      'message' => sprintf('Synced %s prompt into ai_agent.%s.', $version, self::AGENT_ID),
    ];
  }

}
