<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\dkan_ai_query\Service\StatsCalculator;
use PHPUnit\Framework\TestCase;

class StatsCalculatorTest extends TestCase {

  private function calc(): StatsCalculator {
    return new StatsCalculator();
  }

  private function rows(array $col, string $name = 'x'): array {
    return array_map(fn($v) => [$name => $v], $col);
  }

  public function testMedianOdd(): void {
    $out = $this->calc()->run($this->rows([1, 3, 2, 5, 4]), [['type' => 'median', 'column' => 'x']]);
    $this->assertSame(3.0, $out['results'][0]['value']);
    $this->assertSame(5, $out['row_count']);
  }

  public function testMedianEven(): void {
    $out = $this->calc()->run($this->rows([1, 2, 3, 4]), [['type' => 'median', 'column' => 'x']]);
    $this->assertSame(2.5, $out['results'][0]['value']);
  }

  public function testPercentileMatchesMedianAt50(): void {
    $rows = $this->rows([10, 20, 30, 40, 50, 60, 70, 80, 90, 100]);
    $out = $this->calc()->run($rows, [
      ['type' => 'median', 'column' => 'x'],
      ['type' => 'percentile', 'column' => 'x', 'p' => 50],
    ]);
    $this->assertSame($out['results'][0]['value'], $out['results'][1]['value']);
  }

  public function testPercentileLinearInterpolation(): void {
    // 10 values, p=90 → rank = 8.1 → 90 + 0.1*(100-90) = 91.0.
    $rows = $this->rows([10, 20, 30, 40, 50, 60, 70, 80, 90, 100]);
    $out = $this->calc()->run($rows, [['type' => 'percentile', 'column' => 'x', 'p' => 90]]);
    $this->assertEqualsWithDelta(91.0, $out['results'][0]['value'], 1e-9);
  }

  public function testPercentileRejectsOutOfRange(): void {
    $out = $this->calc()->run($this->rows([1, 2]), [['type' => 'percentile', 'column' => 'x', 'p' => 100]]);
    $this->assertSame('invalid_p', $out['results'][0]['error']);
  }

  public function testStddevSampleNotPopulation(): void {
    // [2,4,4,4,5,5,7,9] sample stddev = 2.13809 (n-1=7).
    $rows = $this->rows([2, 4, 4, 4, 5, 5, 7, 9]);
    $out = $this->calc()->run($rows, [['type' => 'stddev', 'column' => 'x']]);
    $this->assertEqualsWithDelta(2.13809, $out['results'][0]['value'], 1e-4);
  }

  public function testVariance(): void {
    $rows = $this->rows([2, 4, 4, 4, 5, 5, 7, 9]);
    $out = $this->calc()->run($rows, [['type' => 'variance', 'column' => 'x']]);
    $this->assertEqualsWithDelta(4.5714, $out['results'][0]['value'], 1e-3);
  }

  public function testStddevInsufficientRows(): void {
    $out = $this->calc()->run($this->rows([5]), [['type' => 'stddev', 'column' => 'x']]);
    $this->assertSame('insufficient_rows', $out['results'][0]['error']);
  }

  public function testQuartiles(): void {
    $rows = $this->rows([1, 2, 3, 4, 5, 6, 7, 8, 9]);
    $out = $this->calc()->run($rows, [['type' => 'quartiles', 'column' => 'x']]);
    $v = $out['results'][0]['value'];
    $this->assertEqualsWithDelta(3.0, $v['q1'], 1e-9);
    $this->assertEqualsWithDelta(5.0, $v['q2'], 1e-9);
    $this->assertEqualsWithDelta(7.0, $v['q3'], 1e-9);
    $this->assertEqualsWithDelta(4.0, $v['iqr'], 1e-9);
  }

  public function testCorrelationPerfectPositive(): void {
    $rows = [];
    for ($i = 1; $i <= 10; $i++) {
      $rows[] = ['x' => $i, 'y' => 2 * $i + 3];
    }
    $out = $this->calc()->run($rows, [['type' => 'correlation', 'columns' => ['x', 'y']]]);
    $this->assertEqualsWithDelta(1.0, $out['results'][0]['value'], 1e-9);
  }

  public function testCorrelationPerfectNegative(): void {
    $rows = [];
    for ($i = 1; $i <= 10; $i++) {
      $rows[] = ['x' => $i, 'y' => -3 * $i + 1];
    }
    $out = $this->calc()->run($rows, [['type' => 'correlation', 'columns' => ['x', 'y']]]);
    $this->assertEqualsWithDelta(-1.0, $out['results'][0]['value'], 1e-9);
  }

  public function testCorrelationZeroVariance(): void {
    $rows = [['x' => 5, 'y' => 1], ['x' => 5, 'y' => 2], ['x' => 5, 'y' => 3]];
    $out = $this->calc()->run($rows, [['type' => 'correlation', 'columns' => ['x', 'y']]]);
    $this->assertSame('zero_variance', $out['results'][0]['error']);
  }

  public function testCorrelationNeedsTwoColumnNames(): void {
    $rows = [['x' => 1, 'y' => 2]];
    $out = $this->calc()->run($rows, [['type' => 'correlation', 'columns' => ['x']]]);
    $this->assertSame('invalid_columns', $out['results'][0]['error']);
  }

  public function testNumericStringCoercion(): void {
    // DKAN stores cells as text — values like "9.5" must be treated as numeric.
    $rows = $this->rows(['1', '2', '3', '4', '5']);
    $out = $this->calc()->run($rows, [['type' => 'median', 'column' => 'x']]);
    $this->assertSame(3.0, $out['results'][0]['value']);
  }

  public function testNonNumericRowsAreSkippedAndCounted(): void {
    $rows = $this->rows([1, 'foo', 2, NULL, 3, '', 4]);
    $out = $this->calc()->run($rows, [['type' => 'median', 'column' => 'x']]);
    $this->assertSame(2.5, $out['results'][0]['value']);
    $this->assertSame(3, $out['results'][0]['skipped']);
  }

  public function testEmptyColumnReturnsError(): void {
    $rows = $this->rows(['a', 'b', 'c']);
    $out = $this->calc()->run($rows, [['type' => 'median', 'column' => 'x']]);
    $this->assertSame('no_numeric_values', $out['results'][0]['error']);
  }

  public function testMissingColumnReturnsError(): void {
    $out = $this->calc()->run([['x' => 1]], [['type' => 'median']]);
    $this->assertSame('invalid_column', $out['results'][0]['error']);
  }

  public function testUnknownOperationReturnsError(): void {
    $out = $this->calc()->run([['x' => 1]], [['type' => 'kurtosis', 'column' => 'x']]);
    $this->assertSame('unknown_operation', $out['results'][0]['error']);
  }

  public function testMultipleOpsInOneCall(): void {
    $rows = $this->rows([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
    $out = $this->calc()->run($rows, [
      ['type' => 'median', 'column' => 'x'],
      ['type' => 'percentile', 'column' => 'x', 'p' => 90],
      ['type' => 'stddev', 'column' => 'x'],
    ]);
    $this->assertCount(3, $out['results']);
    $this->assertSame(10, $out['row_count']);
  }

  public function testWarningPresent(): void {
    $out = $this->calc()->run($this->rows([1, 2, 3]), [['type' => 'median', 'column' => 'x']]);
    $this->assertNotEmpty($out['warnings']);
    $this->assertStringContainsString('row_cap_hit', $out['warnings'][0]);
  }

}
