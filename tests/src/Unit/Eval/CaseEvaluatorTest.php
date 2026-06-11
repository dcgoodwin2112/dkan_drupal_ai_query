<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Eval;

use Drupal\dkan_ai_query\Eval\CaseEvaluator;
use Drupal\dkan_ai_query\Eval\CaseResult;
use Drupal\dkan_ai_query\Eval\GoldenCase;
use PHPUnit\Framework\TestCase;

/**
 * Tests CaseEvaluator pass/fail outcomes and failure categories.
 *
 * @group dkan_ai_query
 */
class CaseEvaluatorTest extends TestCase {

  /**
   * Builds a GoldenCase from overrides plus minimal defaults.
   */
  protected function makeCase(array $overrides = []): GoldenCase {
    return GoldenCase::fromArray($overrides + [
      'id' => 'test_case',
      'question' => 'Q?',
    ]);
  }

  /**
   * An answer matching expected_answer_pattern passes.
   */
  public function testPatternMatchPasses(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '22[,.]?008']);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'Houston had 22,008 violent crimes.');
    $this->assertSame(CaseResult::OUTCOME_PASS, $outcome);
    $this->assertNull($cat);
  }

  /**
   * An answer missing the expected pattern fails as wrong_summary.
   */
  public function testPatternMissFails(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '99999']);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'Houston had 22,008 violent crimes.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('wrong_summary', $cat);
  }

  /**
   * A refusal-style answer passes when a refusal is expected.
   */
  public function testExpectedRefusalPasses(): void {
    $case = $this->makeCase(['expected_refusal' => TRUE]);
    [$outcome] = (new CaseEvaluator())->evaluate($case, 'I cannot find a matching dataset for that question.');
    $this->assertSame(CaseResult::OUTCOME_PASS, $outcome);
  }

  /**
   * Answering when a refusal was expected fails as should_have_refused.
   */
  public function testExpectedRefusalButAnsweredFails(): void {
    $case = $this->makeCase(['expected_refusal' => TRUE]);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'The answer is 42.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('should_have_refused', $cat);
  }

  /**
   * Failed refusal cases use the configured expected_failure_category.
   */
  public function testExpectedRefusalUsesFailureCategoryWhenSet(): void {
    $case = $this->makeCase([
      'expected_refusal' => TRUE,
      'expected_failure_category' => 'dsl_limitation',
    ]);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'The 90th percentile is 1234.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('dsl_limitation', $cat);
  }

  /**
   * Refusing when an answer was expected fails as should_have_answered.
   */
  public function testRefusalWhenAnswerExpectedFails(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '\\d+']);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'I cannot determine that.');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('should_have_answered', $cat);
  }

  /**
   * An execution error yields the error outcome and execution_error.
   */
  public function testExecutionErrorReturnsErrorOutcome(): void {
    $case = $this->makeCase();
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '', 'API timeout');
    $this->assertSame(CaseResult::OUTCOME_ERROR, $outcome);
    $this->assertSame('execution_error', $cat);
  }

  /**
   * A whitespace-only answer fails as empty_answer.
   */
  public function testEmptyAnswerFails(): void {
    $case = $this->makeCase();
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '   ');
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('empty_answer', $cat);
  }

  /**
   * A structured refusal passes an expected-refusal case.
   */
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

  /**
   * A structured refusal fails a case that expected an answer.
   */
  public function testStructuredRefusalWhenAnswerExpectedFails(): void {
    $case = $this->makeCase(['expected_answer_pattern' => '\\d+']);
    $refusal = ['refused' => TRUE, 'reason_category' => 'dsl_limitation', 'explanation' => 'Needs LAG.'];
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '', NULL, $refusal);
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    // Mapped from STRUCTURED_REFUSAL_TO_CATEGORY.
    $this->assertSame('should_have_answered', $cat);
  }

  /**
   * Tests isStructuredDslLimitRefusal() category checks.
   */
  public function testIsStructuredDslLimitRefusal(): void {
    $evaluator = new CaseEvaluator();
    $this->assertTrue($evaluator->isStructuredDslLimitRefusal([
      'refused' => TRUE,
      'reason_category' => 'dsl_limitation',
    ]));
    $this->assertFalse($evaluator->isStructuredDslLimitRefusal([
      'refused' => TRUE,
      'reason_category' => 'no_matching_dataset',
    ]));
    $this->assertFalse($evaluator->isStructuredDslLimitRefusal(NULL));
  }

  /**
   * Tests looksLikeDslLimitRefusal() text heuristics.
   */
  public function testDslLimitRefusalDetection(): void {
    $evaluator = new CaseEvaluator();
    $this->assertTrue($evaluator->looksLikeDslLimitRefusal(
      'I cannot answer this because it requires a window function (LAG) which the datastore does not support.'
    ));
    $this->assertFalse($evaluator->looksLikeDslLimitRefusal(
      'I cannot find a matching dataset for that question.'
    ));
  }

  /**
   * A case asserting expected_tool_calls passes when the tool was invoked.
   */
  public function testRequiredToolCallPresent(): void {
    $case = $this->makeCase([
      'expected_answer_pattern' => '\\d+',
      'expected_tool_calls' => ['compute_stats'],
    ]);
    $toolCalls = [
      ['tool' => 'query_datastore', 'input' => [], 'iteration' => 1, 'output_bytes' => 0],
      ['tool' => 'compute_stats', 'input' => [], 'iteration' => 2, 'output_bytes' => 0],
    ];
    [$outcome] = (new CaseEvaluator())->evaluate($case, 'The median is 42.', NULL, NULL, $toolCalls);
    $this->assertSame(CaseResult::OUTCOME_PASS, $outcome);
  }

  /**
   * Missing a required tool fails as missing_required_tool.
   */
  public function testRequiredToolCallMissingFails(): void {
    $case = $this->makeCase([
      'expected_answer_pattern' => '\\d+',
      'expected_tool_calls' => ['compute_stats'],
    ]);
    $toolCalls = [
      ['tool' => 'query_datastore', 'input' => [], 'iteration' => 1, 'output_bytes' => 0],
    ];
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, 'The median is 42.', NULL, NULL, $toolCalls);
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('missing_required_tool', $cat);
  }

  /**
   * Invoking a forbidden tool fails as used_forbidden_tool.
   */
  public function testForbiddenToolCallFails(): void {
    $case = $this->makeCase([
      'expected_answer_pattern' => '\\d+',
      'forbidden_tool_calls' => ['sample_rows'],
    ]);
    $toolCalls = [
      ['tool' => 'query_datastore', 'input' => [], 'iteration' => 1, 'output_bytes' => 0],
      ['tool' => 'sample_rows', 'input' => [], 'iteration' => 2, 'output_bytes' => 0],
    ];
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '5 rows.', NULL, NULL, $toolCalls);
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('used_forbidden_tool', $cat);
  }

  /**
   * Answer text matching forbidden_answer_pattern fails the case.
   */
  public function testForbiddenAnswerPatternFails(): void {
    $case = $this->makeCase([
      'expected_answer_pattern' => 'Asthma Prevalence',
      'forbidden_answer_pattern' => '95f8eac4',
    ]);
    [$outcome, $cat] = (new CaseEvaluator())->evaluate(
      $case,
      'The Asthma Prevalence dataset (UUID: 95f8eac4-…) has 3 distinct age ranges.',
    );
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('forbidden_text_in_answer', $cat);
  }

  /**
   * Structured refusal whose category matches expected_refusal_category passes.
   */
  public function testExpectedRefusalCategoryMatch(): void {
    $case = $this->makeCase([
      'expected_refusal' => TRUE,
      'expected_refusal_category' => 'out_of_scope',
    ]);
    $refusal = ['refused' => TRUE, 'reason_category' => 'out_of_scope', 'explanation' => 'Off topic.'];
    [$outcome] = (new CaseEvaluator())->evaluate($case, '', NULL, $refusal);
    $this->assertSame(CaseResult::OUTCOME_PASS, $outcome);
  }

  /**
   * Refusal with the wrong category fails as wrong_refusal_category.
   */
  public function testExpectedRefusalCategoryMismatchFails(): void {
    $case = $this->makeCase([
      'expected_refusal' => TRUE,
      'expected_refusal_category' => 'out_of_scope',
    ]);
    $refusal = ['refused' => TRUE, 'reason_category' => 'no_matching_dataset', 'explanation' => 'No data.'];
    [$outcome, $cat] = (new CaseEvaluator())->evaluate($case, '', NULL, $refusal);
    $this->assertSame(CaseResult::OUTCOME_FAIL, $outcome);
    $this->assertSame('wrong_refusal_category', $cat);
  }

}
