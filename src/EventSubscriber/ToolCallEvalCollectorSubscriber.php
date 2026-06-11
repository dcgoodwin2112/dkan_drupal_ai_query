<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\dkan_ai_query\Service\EvalToolCallCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records every tool execution into EvalToolCallCollector.
 *
 * Always-on subscriber — the collector is cheap and per-thread. The eval
 * runner reads and clears after each case; live HTTP requests don't read,
 * so their entries accumulate harmlessly until the request ends and the
 * service goes out of scope.
 *
 * Runs at priority -100 so artifact / refusal subscribers (priority 0)
 * have already mutated the tool's output by the time we measure size.
 */
class ToolCallEvalCollectorSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected EvalToolCallCollector $collector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentToolFinishedExecutionEvent::EVENT_NAME => ['onToolFinished', -100],
    ];
  }

  /**
   * Capture a single tool execution.
   */
  public function onToolFinished(AgentToolFinishedExecutionEvent $event): void {
    $threadId = $event->getThreadId() ?: $event->getAgentRunnerId();
    if (!$threadId) {
      return;
    }
    $tool = $event->getTool();
    try {
      $input = $tool->getContextValues();
    }
    catch (\Throwable) {
      $input = [];
    }
    try {
      $output = $tool->getReadableOutput();
    }
    catch (\Throwable) {
      $output = '';
    }
    $this->collector->record(
      $threadId,
      $tool->getFunctionName(),
      $input,
      strlen($output),
    );
  }

}
