<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Eval;

/**
 * Decides pass/fail for a single case given the agent's output.
 *
 * Phase 1 detection is heuristic. When RefuseTool lands in Phase 3, refusal
 * detection moves from regex to a structured signal.
 */
class CaseEvaluator {

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
  public function evaluate(GoldenCase $case, string $answer, ?string $errorMessage = NULL): array {
    if ($errorMessage !== NULL) {
      return [CaseResult::OUTCOME_ERROR, 'execution_error'];
    }

    $isRefusal = $this->looksLikeRefusal($answer);

    if ($case->expectedRefusal) {
      if ($isRefusal) {
        return [CaseResult::OUTCOME_PASS, NULL];
      }
      return [CaseResult::OUTCOME_FAIL, $case->expectedFailureCategory ?? 'should_have_refused'];
    }

    if ($isRefusal) {
      return [CaseResult::OUTCOME_FAIL, 'should_have_answered'];
    }

    if ($case->expectedAnswerPattern !== NULL && $case->expectedAnswerPattern !== '') {
      $delim = '/';
      $pattern = $delim . str_replace($delim, '\\' . $delim, $case->expectedAnswerPattern) . $delim . 'i';
      if (@preg_match($pattern, $answer) !== 1) {
        return [CaseResult::OUTCOME_FAIL, 'wrong_summary'];
      }
    }

    if (trim($answer) === '') {
      return [CaseResult::OUTCOME_FAIL, 'empty_answer'];
    }

    return [CaseResult::OUTCOME_PASS, NULL];
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
