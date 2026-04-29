<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\Eval;

use Drupal\dkan_drupal_ai_query\Eval\CaseEvaluator;
use Drupal\dkan_drupal_ai_query\Eval\CaseResult;
use Drupal\dkan_drupal_ai_query\Eval\GoldenCase;
use PHPUnit\Framework\TestCase;

class CaseEvaluatorTest extends TestCase {

  protected function makeCase(array $overrides = []): GoldenCase {
    return GoldenCase::fromArray($overrides + [
      'id' => 'test_case',
      'question' => 'Q?',
    ]);
  }

  public function testPatternMatchPasses(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '22[,.]?008']);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'Houston had 22,008 violent crimes.');
    $this->assertSame(CaseResult::OUTCOME_PASS, $outcome);
    $this->assertNull($cat);
  }

  public function testPatternMissFails(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '99999']);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'Houston had 22,008 violent crimes.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('wrong_summary', $cat);
  }

  public function testExpectedRefusalPasses(): void {
    $case = $this->makeCase(['expected_refusal' => TRUE]);
    [$outcome] = (new CaseEvaluator())->evaluate($case, 'I cannot find a matching dataset for that question.');
    $this->assertSame(CaseResult::OUTCOME_PASS, $outcome);
  }

  public function testExpectedRefusalButAnsweredFails(): void {
    $case = $this->makeCase(['expected_refusal' => TRUE]);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'The answer is 42.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('should_have_refused', $cat);
  }

  public function testExpectedRefusalUsesFailureCategoryWhenSet(): void {
    $case = $this->makeCase([
      'expected_refusal' => TRUE,
      'expected_failure_category' => 'dsl_limitation',
    ]);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'The 90th percentile is 1234.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('dsl_limitation', $cat);
  }

  public function testRefusalWhenAnswerExpectedFails(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '\\d+']);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'I cannot determine that.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('should_have_answered', $cat);
  }

  public function testExecutionErrorReturnsErrorOutcome(): void {
    $case = $this->makeCase();
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '', 'API timeout');
    $this->assertSame(CaseResult::OUTCOME_ERROR, $outcome);
    $this->assertSame('execution_error', $cat);
  }

  public function testEmptyAnswerFails(): void {
    $case = $this->makeCase();
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '   ');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('empty_answer', $cat);
  }

  public function testStructuredRefusalPassesExpectedRefusal(): void {
    $case = $this->makeCase(['expected_refusal' => TRUE]);
    $refusal = ['refused' => TRUE, 'reason_category' => 'no_matching_dataset', 'explanation' => 'No catalog match.'];
    [$outcome, $cat] = (new CaseEvaluator())->evaluate(
      $case,
      // The text answer is irrelevant when a structured refusal is set.
      'irrelevant text',
      NULL,
      $refusal,
    );
    $this->assertSame(CaseResult::OUTCOME_PASS, $outcome);
    $this->assertNull($cat);
  }

  public function testStructuredRefusalWhenAnswerExpectedFails(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '\\d+']);
    $refusal = ['refused' => TRUE, 'reason_category' => 'dsl_limitation', 'explanation' => 'Needs LAG.'];
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '', NULL, $refusal);
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    // Mapped from STRUCTURED_REFUSAL_TO_CATEGORY.
    $this->assertSame('should_have_answered', $cat);
  }

  public function testIsStructuredDslLimitRefusal(): void {
    $evaluator = new CaseEvaluator();
    $this->assertTrue($evaluator->isStructuredDslLimitRefusal([
      'refused' => TRUE, 'reason_category' => 'dsl_limitation',
    ]));
    $this->assertFalse($evaluator->isStructuredDslLimitRefusal([
      'refused' => TRUE, 'reason_category' => 'no_matching_dataset',
    ]));
    $this->assertFalse($evaluator->isStructuredDslLimitRefusal(NULL));
  }

  public function testDslLimitRefusalDetection(): void {
    $evaluator = new CaseEvaluator();
    $this->assertTrue($evaluator->looksLikeDslLimitRefusal(
      'I cannot answer this because it requires a window function (LAG) which the datastore does not support.'
    ));
    $this->assertFalse($evaluator->looksLikeDslLimitRefusal(
      'I cannot find a matching dataset for that question.'
    ));
  }

}
