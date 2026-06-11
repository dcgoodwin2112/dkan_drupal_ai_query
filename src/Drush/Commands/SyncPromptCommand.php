<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query\Drush\Commands;

use Drupal\dkan_ai_query\Service\AgentPromptSync;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command for syncing the system prompt markdown into the agent config.
 */
class SyncPromptCommand extends DrushCommands {

  public function __construct(
    protected AgentPromptSync $sync,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('dkan_ai_query.agent_prompt_sync'),
    );
  }

  /**
   * Sync prompts/query_system_prompt.v{N}.md into the dkan_data_query agent.
   */
  #[CLI\Command(name: 'dkan-aiq:sync-prompt', aliases: ['dkan-aiq-sync-prompt'])]
  #[CLI\Option(name: 'prompt-version', description: 'Prompt version to sync (e.g., v4). Defaults to SystemPromptLoader::DEFAULT_VERSION.')]
  #[CLI\Usage(name: 'drush dkan-aiq:sync-prompt', description: 'Sync the active prompt version.')]
  #[CLI\Usage(name: 'drush dkan-aiq:sync-prompt --prompt-version=v3', description: 'Roll the agent back to v3.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function syncPrompt(array $options = ['prompt-version' => self::OPT]): int {
    $version = !empty($options['prompt-version']) ? (string) $options['prompt-version'] : NULL;
    $result = $this->sync->sync($version);
    $tag = match ($result['status']) {
      'synced' => '<info>SYNCED</info>',
      'unchanged' => '<comment>UNCHANGED</comment>',
      default => '<error>ERROR</error>',
    };
    $this->output()->writeln(sprintf('%s %s', $tag, $result['message']));
    return $result['status'] === 'synced' || $result['status'] === 'unchanged'
      ? self::EXIT_SUCCESS
      : self::EXIT_FAILURE;
  }

}
