<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Eval;

/**
 * Outcome of running one golden case through the agent.
 */
final class CaseResult {

  public const OUTCOME_PASS = 'pass';
  public const OUTCOME_FAIL = 'fail';
  public const OUTCOME_ERROR = 'error';

  public function __construct(
    public readonly string $caseId,
    public readonly string $question,
    public readonly string $outcome,
    public readonly ?string $failureCategory,
    public readonly string $answer,
    public readonly array $toolCalls,
    public readonly array $artifacts,
    public readonly int $durationMs,
    public readonly string $provider,
    public readonly string $model,
    public readonly string $executedAt,
    public readonly ?string $errorMessage = NULL,
  ) {}

  /**
   * Serialize to a plain associative array for JSONL output.
   */
  public function toArray(): array {
    return [
      'case_id' => $this->caseId,
      'question' => $this->question,
      'outcome' => $this->outcome,
      'failure_category' => $this->failureCategory,
      'answer' => $this->answer,
      'tool_calls' => $this->toolCalls,
      'artifacts' => $this->artifacts,
      'duration_ms' => $this->durationMs,
      'provider' => $this->provider,
      'model' => $this->model,
      'executed_at' => $this->executedAt,
      'error_message' => $this->errorMessage,
    ];
  }

}
