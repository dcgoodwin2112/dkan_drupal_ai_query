<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Eval;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Task\Task;
use Drupal\dkan_drupal_ai_query\Service\ArtifactStorage;
use Psr\Log\LoggerInterface;

/**
 * Runs a list of golden cases through the dkan_data_query agent.
 *
 * Bypasses NlQueryController to avoid HTTP, polling, and conversation
 * persistence. Same agent, same tools, same prompt. Each case runs in
 * isolation; with --cache-clear (default) all Drupal caches are flushed
 * between cases.
 *
 * Progress tracking is disabled for eval runs because ai_agents writes
 * status updates to PrivateTempStatusStorage which requires a session,
 * and Drush has none. Status events are a UI affordance, not part of
 * pass/fail. ArtifactStorage is also session-backed; it soft-fails in
 * CLI so artifact capture during a run is simply dropped.
 */
class EvalRunner {

  public function __construct(
    protected AiAgentManager $agentManager,
    protected AiProviderPluginManager $providerManager,
    protected ArtifactStorage $artifacts,
    protected CaseEvaluator $evaluator,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Run a list of golden cases through the agent.
   *
   * @param \Drupal\dkan_drupal_ai_query\Eval\GoldenCase[] $cases
   *   Cases to run, in order.
   * @param string $providerId
   *   AI provider plugin id.
   * @param string $modelId
   *   Model id passed to the provider.
   * @param bool $clearCaches
   *   If TRUE, drupal_flush_all_caches() runs before each case.
   * @param int $sleepSeconds
   *   Seconds to pause between cases. Used to dodge LLM rate limits.
   * @param callable|null $progress
   *   Optional callback: ($index, $total, GoldenCase, CaseResult).
   *
   * @return \Drupal\dkan_drupal_ai_query\Eval\CaseResult[]
   *   Results in the same order as $cases.
   */
  public function run(
    array $cases,
    string $providerId,
    string $modelId,
    bool $clearCaches = TRUE,
    int $sleepSeconds = 0,
    ?callable $progress = NULL,
  ): array {
    $results = [];
    $total = count($cases);
    foreach (array_values($cases) as $i => $case) {
      if ($i > 0 && $sleepSeconds > 0) {
        sleep($sleepSeconds);
      }
      if ($clearCaches) {
        $this->clearCaches();
      }
      $result = $this->runOne($case, $providerId, $modelId);
      $results[] = $result;
      if ($progress !== NULL) {
        $progress($i + 1, $total, $case, $result);
      }
    }
    return $results;
  }

  /**
   * Execute a single case end-to-end and build its result row.
   */
  protected function runOne(GoldenCase $case, string $providerId, string $modelId): CaseResult {
    $threadId = 'eval-' . $case->id . '-' . bin2hex(random_bytes(4));

    $start = hrtime(TRUE);
    $answer = '';
    $errorMessage = NULL;
    try {
      $provider = $this->providerManager->createInstance($providerId);
      $agent = $this->agentManager->createInstance('dkan_data_query');
      $agent->setTask(new Task($case->question));
      $agent->setAiProvider($provider);
      $agent->setModelName($modelId);
      $agent->setAiConfiguration(['temperature' => 0]);
      $agent->setCreateDirectly(TRUE);
      $agent->setRunnerId($threadId);
      $agent->setProgressTracking(FALSE);

      $solvability = $agent->determineSolvability();
      if ($solvability === AiAgentInterface::JOB_SOLVABLE) {
        $answer = (string) $agent->solve();
      }
      else {
        $answer = "Agent did not consider the task solvable. Status: {$solvability}";
      }
    }
    catch (\Throwable $e) {
      $errorMessage = $e->getMessage();
      $this->logger->error('Eval case @id failed: @m', ['@id' => $case->id, '@m' => $errorMessage]);
    }
    $durationMs = (int) ((hrtime(TRUE) - $start) / 1e6);

    [$outcome, $category] = $this->evaluator->evaluate($case, $answer, $errorMessage);

    if (
      $outcome === CaseResult::OUTCOME_PASS
      && $case->expectedFailureCategory === 'dsl_limitation'
      && !$this->evaluator->looksLikeDslLimitRefusal($answer)
    ) {
      $outcome = CaseResult::OUTCOME_FAIL;
      $category = 'dsl_limitation';
    }

    return new CaseResult(
      caseId: $case->id,
      question: $case->question,
      outcome: $outcome,
      failureCategory: $category,
      answer: $answer,
      toolCalls: [],
      artifacts: $this->artifacts->load($threadId),
      durationMs: $durationMs,
      provider: $providerId,
      model: $modelId,
      executedAt: gmdate('c'),
      errorMessage: $errorMessage,
    );
  }

  /**
   * Flush all Drupal caches between cases for deterministic state.
   */
  protected function clearCaches(): void {
    drupal_flush_all_caches();
  }

}
