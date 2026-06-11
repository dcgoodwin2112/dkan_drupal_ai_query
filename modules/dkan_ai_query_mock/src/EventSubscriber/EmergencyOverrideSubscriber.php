<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query_mock\EventSubscriber;

use Drupal\Core\State\StateInterface;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Site-wide kill switch that forces a canned chat response.
 *
 * Activates only when `getenv('DKAN_AIQ_FORCE_MOCK')` is truthy or the State
 * key `dkan_ai_query_mock.force_active` is set. Independent of which
 * provider is selected — useful for staging environments that must guarantee
 * no LLM call escapes regardless of misconfiguration. For day-to-day fixture
 * work, prefer selecting the `dkan_aiq_mock` provider via `ai.settings` so the
 * full ScenarioMatcher flow runs.
 *
 * Returns a single final_answer (no tool calls). Callers that need the agent
 * to drive specific tools should use the provider-plugin path instead.
 */
class EmergencyOverrideSubscriber implements EventSubscriberInterface {

  /**
   * Drupal's State key/value store, used as a runtime kill-switch flag.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private StateInterface $state;

  /**
   * Constructs the subscriber.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 1024],
    ];
  }

  /**
   * Forces a canned ChatOutput when the kill switch is active.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if (!$this->isActive()) {
      return;
    }
    if ($event->getOperationType() !== 'chat') {
      return;
    }
    if ($event->getProviderId() === 'dkan_aiq_mock') {
      // The mock provider already handles the call cleanly; no need to short-
      // circuit it (and doing so would skip ScenarioMatcher).
      return;
    }
    $message = new ChatMessage(
      'assistant',
      "_(dkan_ai_query_mock emergency override active.)_\n\nNo LLM call was made. Selected provider: `" . $event->getProviderId() . '`. ' .
      'Disable by unsetting the DKAN_AIQ_FORCE_MOCK env var or clearing state key `dkan_ai_query_mock.force_active`.',
    );
    $event->setForcedOutputObject(new ChatOutput($message, ['emergency_override' => TRUE], []));
  }

  /**
   * Whether the kill switch should engage for the current request.
   */
  private function isActive(): bool {
    $env = getenv('DKAN_AIQ_FORCE_MOCK');
    if ($env !== FALSE && $env !== '' && $env !== '0' && strtolower((string) $env) !== 'false') {
      return TRUE;
    }
    return (bool) $this->state->get('dkan_ai_query_mock.force_active', FALSE);
  }

}
