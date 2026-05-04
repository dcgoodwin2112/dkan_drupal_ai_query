<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query_mock\Service;

use Drupal\Core\State\StateInterface;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\dkan_drupal_ai_query_mock\Scenario;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Picks which scenario should drive the current `chat()` call.
 *
 * Selection precedence (first match wins):
 *   1. `X-DKAN-Aiq-Scenario` request header (per-call override; useful in tests
 *      and curl probes)
 *   2. `dkan_aiq_scenario` cookie (per-browser-session override; set by the
 *      picker form).
 *   3. State key `dkan_drupal_ai_query_mock.active_scenario` (site-wide active
 *      scenario set via the picker form).
 *   4. First scenario whose `match.question_contains` substrings all appear in
 *      the most recent user message.
 *   5. NULL — caller emits a synthetic stub turn.
 */
final class ScenarioMatcher {

  public const HEADER = 'X-DKAN-Aiq-Scenario';
  public const COOKIE = 'dkan_aiq_scenario';
  public const STATE_KEY = 'dkan_drupal_ai_query_mock.active_scenario';

  public function __construct(
    private readonly ScenarioLoader $loader,
    private readonly RequestStack $requestStack,
    private readonly StateInterface $state,
  ) {}

  /**
   * Resolves the scenario for the current chat call.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The current chat input. Used to find the latest user message for
   *   question_contains matching.
   *
   * @return \Drupal\dkan_drupal_ai_query_mock\Scenario|null
   *   Selected scenario, or NULL if no rule matched.
   */
  public function resolve(ChatInput $input): ?Scenario {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL) {
      $headerId = $request->headers->get(self::HEADER);
      if ($headerId && $scenario = $this->loader->get($headerId)) {
        return $scenario;
      }
      $cookieId = $request->cookies->get(self::COOKIE);
      if ($cookieId && $scenario = $this->loader->get($cookieId)) {
        return $scenario;
      }
    }
    $stateId = $this->state->get(self::STATE_KEY);
    if (is_string($stateId) && $stateId !== '' && $scenario = $this->loader->get($stateId)) {
      return $scenario;
    }
    return $this->matchByQuestion($input);
  }

  /**
   * Returns the most recent user message text from the chat history, or ''.
   *
   * This is the raw message as received by the provider — for the
   * `dkan_data_query` agent that is the wrapped "Task Title:/Task Author:/
   * Task Description:" template, not the user's typed question. Use
   * latestUserQuestion() for matching and display.
   */
  public function latestUserMessage(ChatInput $input): string {
    $messages = $input->getMessages();
    foreach (array_reverse($messages) as $message) {
      if ($message instanceof ChatMessage && $message->getRole() === 'user') {
        return $message->getText();
      }
    }
    return '';
  }

  /**
   * Returns the user's typed question, unwrapped from the agent task template.
   *
   * The dkan_data_query controller builds task text as:
   *   <hints + catalog dump>
   *   <blank line>
   *   <user question>
   * which ai_agents then wraps in:
   *   Task Title: …
   *   Task Author: …
   *   Task Description:
   *   <full task text from above>
   *   --------------------------
   *
   * So the user's question is the LAST paragraph before the dashes (or end of
   * message). We split on blank lines and take the final non-empty block.
   * Falls through to the full message verbatim if the wrapper is absent.
   */
  public function latestUserQuestion(ChatInput $input): string {
    $raw = $this->latestUserMessage($input);
    if ($raw === '') {
      return '';
    }
    if (preg_match('/Task Description:\s*\n(.*?)(?:\n-{5,}|\z)/s', $raw, $matches)) {
      $body = trim($matches[1]);
      $paragraphs = preg_split('/\n\s*\n/', $body) ?: [];
      // Last non-empty paragraph is the user's question; everything before is
      // catalog/hint context the controller prepended.
      for ($i = count($paragraphs) - 1; $i >= 0; $i--) {
        $candidate = trim($paragraphs[$i]);
        if ($candidate !== '') {
          return $candidate;
        }
      }
      return $body;
    }
    return trim($raw);
  }

  /**
   * Picks the first scenario whose question_contains substrings are all in $q.
   */
  private function matchByQuestion(ChatInput $input): ?Scenario {
    $question = mb_strtolower($this->latestUserQuestion($input));
    if ($question === '') {
      return NULL;
    }
    foreach ($this->loader->all() as $scenario) {
      $needles = $scenario->match['question_contains'] ?? [];
      if ($needles === []) {
        continue;
      }
      $hit = TRUE;
      foreach ($needles as $needle) {
        if (mb_stripos($question, (string) $needle) === FALSE) {
          $hit = FALSE;
          break;
        }
      }
      if ($hit) {
        return $scenario;
      }
    }
    return NULL;
  }

}
