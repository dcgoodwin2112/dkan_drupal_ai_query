<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query_mock\Plugin\AiProvider;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\dkan_drupal_ai_query_mock\Service\ScenarioMatcher;

/**
 * Scenario-driven mock provider for the dkan_drupal_ai_query widget.
 *
 * Replays scripted multi-turn responses so the full UI (POST /start, polling
 * /poll, artifact rendering) can be exercised without a live LLM. Real tool
 * execution against the datastore continues unchanged — only the LLM is mocked.
 *
 * Phase 2: returns a canned final_answer for every input. ScenarioLoader and
 * ScenarioMatcher land in phase 3 and replace the stub turn.
 */
#[AiProvider(
  id: 'dkan_aiq_mock',
  label: new TranslatableMarkup('DKAN AI Query Mock'),
)]
class DkanAiqMockProvider extends AiProviderClientBase implements ChatInterface {

  use ChatTrait;

  /**
   * Position within the scripted scenario for the current PHP request.
   *
   * Ai_agents tail-recurses inside one process and reuses this provider
   * instance across loop iterations, so a private property is sufficient
   * for in-request progression. Cross-request continuation will key off
   * thread_id once ScenarioMatcher lands.
   */
  protected int $turnIndex = 0;

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('dkan_drupal_ai_query_mock.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return ['scripted' => 'Scripted scenario'];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if ($operation_type !== NULL && $operation_type !== 'chat') {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // Mock provider needs no credentials.
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedCapabilities(): array {
    return [
      AiModelCapability::ChatTools,
      AiModelCapability::ChatSystemRole,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    if (!$input instanceof ChatInput) {
      throw new \InvalidArgumentException('DkanAiqMockProvider requires a ChatInput; the controller and ai_agents always pass one.');
    }

    // Simulated per-turn latency. The widget polls /poll every 500 ms; without
    // a deliberate stall the agent loop completes in under a single tick, so
    // tool_call / data / chart artifacts never reach the UI even though they
    // were captured server-side. Sleep keeps each turn long enough for at
    // least one poll. Override or disable via the state key.
    $delay_ms = (int) (\Drupal::state()->get('dkan_drupal_ai_query_mock.turn_delay_ms', 600));
    if ($delay_ms > 0) {
      usleep($delay_ms * 1000);
    }

    $turn = $this->scriptedTurn($input);
    $index = $this->turnIndex++;

    if ($turn['type'] === 'final_answer') {
      $message = new ChatMessage('assistant', $turn['content']);
      return new ChatOutput($message, ['mock' => TRUE, 'turn' => $index, 'type' => 'final_answer'], []);
    }

    // Tool-call turn — must mirror OpenAiProvider's shape exactly:
    // an array of ToolsFunctionOutput on the assistant ChatMessage. Wrapping
    // these in a ToolsOutput (as EchoProvider does) crashes the agent in
    // FunctionCallPluginManager::convertToolResponseToObject().
    $toolsInput = $input->getChatTools();
    $tools = [];
    foreach ($turn['calls'] as $call) {
      $name = $call['name'] ?? '';
      $functionInput = $toolsInput?->getFunctionByName($name);
      if (!$functionInput) {
        throw new \RuntimeException(sprintf(
          'Mock provider scripted turn references tool "%s" but it is not registered on the agent. Registered: %s',
          $name,
          implode(', ', $this->registeredToolNames($input)),
        ));
      }
      $tools[] = new ToolsFunctionOutput(
        $functionInput,
        $call['id'] ?? 'mock_' . uniqid('', TRUE),
        $this->substitutePlaceholders($call['args'] ?? []),
      );
    }
    $message = new ChatMessage('assistant', $turn['narration'] ?? '');
    $message->setTools($tools);
    return new ChatOutput($message, ['mock' => TRUE, 'turn' => $index, 'type' => 'tool_calls'], []);
  }

  /**
   * Picks the next scripted turn for the current call.
   *
   * Resolves the active scenario via ScenarioMatcher, then returns the turn
   * at $this->turnIndex. Falls back to a clear stub turn when no scenario
   * matches so the operator immediately sees they need to add or pick one.
   */
  protected function scriptedTurn(ChatInput $input): array {
    $matcher = \Drupal::service('dkan_drupal_ai_query_mock.scenario_matcher');
    $scenario = $matcher->resolve($input);
    if ($scenario === NULL) {
      $question = $matcher->latestUserQuestion($input);
      $loader = \Drupal::service('dkan_drupal_ai_query_mock.scenario_loader');
      $available = array_map(
        static fn ($s) => sprintf('`%s` (%s)', $s->id, implode(' + ', $s->match['question_contains'] ?? ['—'])),
        array_values($loader->all()),
      );
      return [
        'type' => 'final_answer',
        'content' => sprintf(
          "**dkan_aiq_mock — no scenario matched.**\n\nYour question: %s\n\nAvailable scenarios:\n- %s\n\nSet a scenario via the picker form at `/admin/dkan/ai-query/mock-scenarios`, the `%s` cookie, or the `%s` request header.",
          $question === '' ? '_(empty)_' : '"' . $question . '"',
          implode("\n- ", $available) ?: '(none)',
          ScenarioMatcher::COOKIE,
          ScenarioMatcher::HEADER,
        ),
      ];
    }
    return $scenario->turnAt($this->turnIndex);
  }

  /**
   * Returns the names of tools registered on the current agent invocation.
   */
  protected function registeredToolNames(ChatInput $input): array {
    $tools = $input->getChatTools();
    if ($tools === NULL) {
      return [];
    }
    $names = [];
    foreach ($tools->getFunctions() as $fn) {
      $names[] = $fn->getName();
    }
    return $names;
  }

  /**
   * Substitutes ${PLACEHOLDER} tokens in scripted args with state values.
   *
   * Scenarios stay portable across DKAN sites by referencing
   * `${FIXTURE_RESOURCE_ID}` instead of a hardcoded `{identifier}__{version}`,
   * because the version is a per-install timestamp. The fixture loader writes
   * the resolved id to state on install; this method swaps it in at chat-call
   * time. Walks args recursively so the token can sit inside nested arrays
   * (e.g. condition objects).
   */
  protected function substitutePlaceholders(mixed $value): mixed {
    if (is_array($value)) {
      return array_map([$this, 'substitutePlaceholders'], $value);
    }
    if (!is_string($value) || !str_contains($value, '${')) {
      return $value;
    }
    return preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)\}/', function (array $m) {
      return match ($m[1]) {
        'FIXTURE_RESOURCE_ID' => (string) (\Drupal::state()->get('dkan_drupal_ai_query_mock.fixture_resource_id') ?? $m[0]),
        default => $m[0],
      };
    }, $value);
  }

}
