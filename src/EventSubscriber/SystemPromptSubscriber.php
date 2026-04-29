<?php

namespace Drupal\dkan_drupal_ai_query\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\dkan_drupal_ai_query\Service\SystemPromptLoader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Substitutes the dkan_data_query agent prompt from a versioned markdown file.
 *
 * Keeps the prompt source-of-truth in
 * `prompts/query_system_prompt.v{N}.md` instead of the agent YAML so prompt
 * edits are diffable in git, separate from the agent config. Falls back to
 * the YAML-resident prompt if the markdown file is missing.
 */
class SystemPromptSubscriber implements EventSubscriberInterface {

  /**
   * The agent id whose prompt we override.
   */
  protected const AGENT_ID = 'dkan_data_query';

  /**
   * The system prompt loader.
   */
  protected SystemPromptLoader $loader;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  public function __construct(SystemPromptLoader $loader, ?LoggerInterface $logger = NULL) {
    $this->loader = $loader;
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', 10],
    ];
  }

  /**
   * Replace the system prompt with the versioned markdown file's contents.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    if ($event->getAgentId() !== self::AGENT_ID) {
      return;
    }
    $prompt = $this->loader->load($this->loader->activeVersion());
    if ($prompt === NULL || $prompt === '') {
      $this->logger->warning('SystemPromptSubscriber: prompt file missing or empty for version @v; falling back to YAML.', [
        '@v' => $this->loader->activeVersion(),
      ]);
      return;
    }
    $event->setSystemPrompt($prompt);
  }

}
