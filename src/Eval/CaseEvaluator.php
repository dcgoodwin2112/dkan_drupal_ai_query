<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Eval;

/**
 * Decides pass/fail for a single case given the agent's output.
 *
 * Refusal detection prefers a structured RefuseTool payload (Phase 3) when
 * present, and falls back to heuristic regex over the answer text for older
 * runs / cases where the model refused without invoking the tool.
 */
class CaseEvaluator {

  /**
   * Map of refusal reason_category to recorded failure category.
   */
  protected const STRUCTURED_REFUSAL_TO_CATEGORY = [
    'no_matching_dataset' => 'should_have_answered',
    'out_of_scope' => 'should_have_answered',
    'write_request' => 'should_have_answered',
    'out_of_coverage' => 'should_have_answered',
    'dsl_limitation' => 'should_have_answered',
    'repeated_unknown_column' => 'should_have_answered',
    'other' => 'should_have_answered',
  ];

  /**
   * Heuristic patterns that indicate the model declined to answer.
   */
  protected const REFUSAL_PATTERNS = [
    '/\bcannot\b.*\b(find|answer|determine|locate|fulfill)/i',
    '/\b(no|not\s+a)\s+(matching|relevant|suitable)\s+dataset/i',
    '/\bunable\s+to\b/i',
    '/\bcannot\s+(provide|generate|produce|write)/i',
    '/\bdoes(?:n\'t| not)\s+(?:exist|appear|match)/i',
    '/\b(cannot|can\'t|unable)\b.*\b(express|support|handle|compute)/i',
    '/\brequires?\s+(?:capabilities|features|sql)\s+(?:not|that\s+are\s+not)/i',
  ];

  /**
   * Patterns that suggest the model hit a DSL limit (window, percentile, CTE).
   */
  protected const DSL_LIMIT_PATTERNS = [
    '/\bwindow\s+function/i',
    '/\b(LAG|LEAD|ROW_NUMBER|RANK)\s*\(/i',
    '/\bpercentile/i',
    '/\bsubquer(?:y|ies)/i',
    '/\bself.?join/i',
    '/\bCTE\b/i',
  ];

  /**
   * Decide pass/fail and category for a case given the agent's output.
   *
   * Returns a tuple [outcome, category] where outcome is one of the
   * CaseResult::OUTCOME_* constants and category is the failure category
   * (or NULL on pass / on error with category 'execution_error').
   */
  public function evaluate(
    GoldenCase $case,
    string $answer,
    ?string $errorMessage = NULL,
    ?array $structuredRefusal = NULL,
    array $toolCalls = [],
  ): array {
    if ($errorMessage !== NULL) {
      return [CaseResult::OUTCOME_ERROR, 'execution_error'];
    }

    $isRefusal = $structuredRefusal !== NULL || $this->looksLikeRefusal($answer);

    if ($case->expectedRefusal) {
      if (!$isRefusal) {
        return [CaseResult::OUTCOME_FAIL, $case->expectedFailureCategory ?? 'should_have_refused'];
      }
      // Optional category check — pins refusal routing fixes (e.g. off_topic
      // must go through refuse(category: off_topic), not prose).
      if ($case->expectedRefusalCategory !== NULL && $case->expectedRefusalCategory !== '') {
        $actual = $structuredRefusal['reason_category'] ?? NULL;
        if ($actual !== $case->expectedRefusalCategory) {
          return [CaseResult::OUTCOME_FAIL, 'wrong_refusal_category'];
        }
      }
      return $this->checkToolCallExpectations($case, $toolCalls)
        ?? [CaseResult::OUTCOME_PASS, NULL];
    }

    if ($isRefusal) {
      // Structured refusal carries a deterministic reason; map it through
      // STRUCTURED_REFUSAL_TO_CATEGORY so the eval report records the model's
      // own category rather than a generic catch-all.
      if ($structuredRefusal !== NULL) {
        $cat = $structuredRefusal['reason_category'] ?? 'other';
        return [CaseResult::OUTCOME_FAIL, self::STRUCTURED_REFUSAL_TO_CATEGORY[$cat] ?? 'should_have_answered'];
      }
      return [CaseResult::OUTCOME_FAIL, 'should_have_answered'];
    }

    if ($case->expectedAnswerPattern !== NULL && $case->expectedAnswerPattern !== '') {
      $delim = '/';
      $pattern = $delim . str_replace($delim, '\\' . $delim, $case->expectedAnswerPattern) . $delim . 'i';
      if (@preg_match($pattern, $answer) !== 1) {
        return [CaseResult::OUTCOME_FAIL, 'wrong_summary'];
      }
    }

    if ($case->forbiddenAnswerPattern !== NULL && $case->forbiddenAnswerPattern !== '') {
      $delim = '/';
      $pattern = $delim . str_replace($delim, '\\' . $delim, $case->forbiddenAnswerPattern) . $delim . 'i';
      if (@preg_match($pattern, $answer) === 1) {
        return [CaseResult::OUTCOME_FAIL, 'forbidden_text_in_answer'];
      }
    }

    if (trim($answer) === '') {
      return [CaseResult::OUTCOME_FAIL, 'empty_answer'];
    }

    return $this->checkToolCallExpectations($case, $toolCalls)
      ?? [CaseResult::OUTCOME_PASS, NULL];
  }

  /**
   * Verify required and forbidden tool calls.
   *
   * Pins routing fixes (e.g. "first N must go through query_datastore, not
   * sample_rows") so a regression in the prompt or in tool descriptions gets
   * caught the next time the eval suite runs.
   *
   * @return array{0:string,1:string}|null
   *   Failure outcome+category, or NULL when all checks pass.
   */
  protected function checkToolCallExpectations(GoldenCase $case, array $toolCalls): ?array {
    if (!$case->expectedToolCalls && !$case->forbiddenToolCalls) {
      return NULL;
    }
    $invokedTools = array_unique(array_map(
      static fn(array $c) => (string) ($c['tool'] ?? ''),
      $toolCalls,
    ));
    foreach ($case->expectedToolCalls as $required) {
      if (!in_array($required, $invokedTools, TRUE)) {
        return [CaseResult::OUTCOME_FAIL, 'missing_required_tool'];
      }
    }
    foreach ($case->forbiddenToolCalls as $forbidden) {
      if (in_array($forbidden, $invokedTools, TRUE)) {
        return [CaseResult::OUTCOME_FAIL, 'used_forbidden_tool'];
      }
    }
    return NULL;
  }

  /**
   * TRUE when the structured refusal payload claims a DSL-limit refusal.
   */
  public function isStructuredDslLimitRefusal(?array $structuredRefusal): bool {
    return is_array($structuredRefusal)
      && ($structuredRefusal['reason_category'] ?? NULL) === 'dsl_limitation';
  }

  /**
   * Check the answer text against refusal heuristics.
   */
  protected function looksLikeRefusal(string $answer): bool {
    foreach (self::REFUSAL_PATTERNS as $rx) {
      if (preg_match($rx, $answer) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * TRUE if the answer is both a refusal and mentions a DSL-limit feature.
   */
  public function looksLikeDslLimitRefusal(string $answer): bool {
    if (!$this->looksLikeRefusal($answer)) {
      return FALSE;
    }
    foreach (self::DSL_LIMIT_PATTERNS as $rx) {
      if (preg_match($rx, $answer) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
