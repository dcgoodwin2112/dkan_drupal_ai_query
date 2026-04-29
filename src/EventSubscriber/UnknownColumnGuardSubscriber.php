<?php

namespace Drupal\dkan_drupal_ai_query\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\dkan_drupal_ai_query\Service\RefusalCollector;
use Drupal\dkan_drupal_ai_query\Service\UnknownColumnCounter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trips the agent into a refusal after N unknown_column errors in one solve.
 *
 * Without this guard, an agent that keeps inventing column names will burn
 * the full max_loops budget retrying the same mistake. We bump a per-thread
 * counter when DatastoreTools returns the structured `error: unknown_column`
 * payload, and at the trip threshold we rewrite the tool output to a
 * refusal-shaped JSON the agent will react to. We also record a structured
 * refusal so eval scoring picks it up deterministically.
 *
 * Runs at priority 10 so the rewritten output reaches ArtifactCaptureSubscriber
 * (priority 0), which then captures the refusal artifact normally.
 */
class UnknownColumnGuardSubscriber implements EventSubscriberInterface {

  /**
   * Tool function names whose output we inspect.
   */
  protected const GUARDED_TOOLS = ['query_datastore', 'query_datastore_join'];

  public function __construct(
    protected UnknownColumnCounter $counter,
    protected RefusalCollector $refusals,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentToolFinishedExecutionEvent::EVENT_NAME => ['onToolFinished', 10],
    ];
  }

  public function onToolFinished(AgentToolFinishedExecutionEvent $event): void {
    $tool = $event->getTool();
    if (!in_array($tool->getFunctionName(), self::GUARDED_TOOLS, TRUE)) {
      return;
    }
    $threadId = $event->getThreadId() ?: $event->getAgentRunnerId();
    if (!$threadId) {
      return;
    }
    $raw = $tool->getReadableOutput();
    if (!$raw) {
      return;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || ($decoded['error'] ?? NULL) !== 'unknown_column') {
      return;
    }

    $count = $this->counter->bump($threadId);
    if ($count < UnknownColumnCounter::tripThreshold()) {
      return;
    }

    $payload = [
      'refused' => TRUE,
      'reason_category' => 'repeated_unknown_column',
      'explanation' => sprintf(
        'Stopping after %d unknown_column errors in this turn. The most recent unknown column was "%s" on resource "%s". Available columns: %s.',
        $count,
        $decoded['column'] ?? '(unknown)',
        $decoded['resource_id'] ?? '(unknown)',
        implode(', ', $decoded['available_columns'] ?? []) ?: '(none)',
      ),
      'datasets_searched' => array_filter([$decoded['resource_id'] ?? NULL]),
    ];
    $tool->setOutput(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $this->refusals->record($threadId, $payload);
  }

}
