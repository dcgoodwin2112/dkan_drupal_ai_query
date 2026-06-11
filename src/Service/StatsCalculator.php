<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query\Service;

/**
 * Pure-PHP statistics for ops the DSL cannot express.
 *
 * Sibling of query_datastore aggregates: SQL handles sum/count/avg/min/max on
 * the full table; this service handles median, percentile, stddev, variance,
 * quartiles, and correlation on rows the agent has already fetched.
 */
class StatsCalculator {

  /**
   * Run a batch of operations against a row set.
   *
   * @param array $rows
   *   Array of associative arrays (rows) as returned by query_datastore.
   * @param array $ops
   *   Array of operation specs. See execute*() methods for shapes.
   *
   * @return array
   *   ['row_count' => int, 'results' => array, 'warnings' => array].
   */
  public function run(array $rows, array $ops): array {
    $results = [];
    foreach ($ops as $op) {
      $results[] = $this->dispatch($rows, $op);
    }
    return [
      'row_count' => count($rows),
      'results' => $results,
      'warnings' => [
        'Computed on the rows you passed in. If sanity_flags.row_cap_hit was true on the source query_datastore call, this is a sample, not the full population.',
      ],
    ];
  }

  /**
   * Dispatch one operation to its handler.
   */
  private function dispatch(array $rows, array $op): array {
    $type = $op['type'] ?? '';
    return match ($type) {
      'median' => $this->median($rows, $op),
      'percentile' => $this->percentile($rows, $op),
      'stddev' => $this->stddev($rows, $op),
      'variance' => $this->variance($rows, $op),
      'quartiles' => $this->quartiles($rows, $op),
      'correlation' => $this->correlation($rows, $op),
      default => $this->error($op, 'unknown_operation', "Unknown op type '$type'. Supported: median, percentile, stddev, variance, quartiles, correlation."),
    };
  }

  /**
   * Median of a column.
   */
  private function median(array $rows, array $op): array {
    [$values, $skipped, $err] = $this->extractColumn($rows, $op);
    if ($err !== NULL) {
      return $this->error($op, ...$err);
    }
    sort($values);
    $value = $this->medianOfSorted($values);
    return $this->ok($op, $value, $skipped);
  }

  /**
   * Percentile of a column at the given p (0 < p < 100).
   */
  private function percentile(array $rows, array $op): array {
    $p = $op['p'] ?? NULL;
    if (!is_numeric($p) || $p <= 0 || $p >= 100) {
      return $this->error($op, 'invalid_p', 'percentile requires p with 0 < p < 100.');
    }
    [$values, $skipped, $err] = $this->extractColumn($rows, $op);
    if ($err !== NULL) {
      return $this->error($op, ...$err);
    }
    sort($values);
    return $this->ok($op, $this->percentileOfSorted($values, (float) $p), $skipped);
  }

  /**
   * Sample standard deviation of a column.
   */
  private function stddev(array $rows, array $op): array {
    [$values, $skipped, $err] = $this->extractColumn($rows, $op);
    if ($err !== NULL) {
      return $this->error($op, ...$err);
    }
    if (count($values) < 2) {
      return $this->error($op, 'insufficient_rows', 'stddev requires at least 2 numeric rows.');
    }
    return $this->ok($op, sqrt($this->sampleVariance($values)), $skipped);
  }

  /**
   * Sample variance of a column.
   */
  private function variance(array $rows, array $op): array {
    [$values, $skipped, $err] = $this->extractColumn($rows, $op);
    if ($err !== NULL) {
      return $this->error($op, ...$err);
    }
    if (count($values) < 2) {
      return $this->error($op, 'insufficient_rows', 'variance requires at least 2 numeric rows.');
    }
    return $this->ok($op, $this->sampleVariance($values), $skipped);
  }

  /**
   * Quartiles q1/q2/q3 and IQR of a column.
   */
  private function quartiles(array $rows, array $op): array {
    [$values, $skipped, $err] = $this->extractColumn($rows, $op);
    if ($err !== NULL) {
      return $this->error($op, ...$err);
    }
    sort($values);
    $q1 = $this->percentileOfSorted($values, 25.0);
    $q2 = $this->percentileOfSorted($values, 50.0);
    $q3 = $this->percentileOfSorted($values, 75.0);
    return $this->ok($op, [
      'q1' => $q1,
      'q2' => $q2,
      'q3' => $q3,
      'iqr' => $q3 - $q1,
    ], $skipped);
  }

  /**
   * Pearson correlation between two columns.
   */
  private function correlation(array $rows, array $op): array {
    $columns = $op['columns'] ?? NULL;
    if (!is_array($columns) || count($columns) !== 2) {
      return $this->error($op, 'invalid_columns', 'correlation requires columns: [a, b].');
    }
    [$colA, $colB] = $columns;
    $xs = [];
    $ys = [];
    $skipped = 0;
    foreach ($rows as $row) {
      $a = $row[$colA] ?? NULL;
      $b = $row[$colB] ?? NULL;
      if (is_numeric($a) && is_numeric($b)) {
        $xs[] = (float) $a;
        $ys[] = (float) $b;
      }
      else {
        $skipped++;
      }
    }
    $n = count($xs);
    if ($n < 2) {
      return $this->error($op, 'insufficient_rows', 'correlation requires at least 2 row pairs with numeric values in both columns.');
    }
    $meanX = array_sum($xs) / $n;
    $meanY = array_sum($ys) / $n;
    $num = 0.0;
    $denX = 0.0;
    $denY = 0.0;
    for ($i = 0; $i < $n; $i++) {
      $dx = $xs[$i] - $meanX;
      $dy = $ys[$i] - $meanY;
      $num += $dx * $dy;
      $denX += $dx * $dx;
      $denY += $dy * $dy;
    }
    if ($denX == 0.0 || $denY == 0.0) {
      return $this->error($op, 'zero_variance', 'correlation undefined when one column has zero variance.');
    }
    return $this->ok($op, $num / sqrt($denX * $denY), $skipped);
  }

  /**
   * Pull numeric values out of a single column.
   *
   * @return array{0: list<float>, 1: int, 2: ?array{0: string, 1: string}}
   *   [values, skipped_count, error] — error is [code, message] or NULL.
   */
  private function extractColumn(array $rows, array $op): array {
    $col = $op['column'] ?? NULL;
    if (!is_string($col) || $col === '') {
      return [[], 0, ['invalid_column', 'op requires a non-empty "column" string.']];
    }
    $values = [];
    $skipped = 0;
    foreach ($rows as $row) {
      $v = $row[$col] ?? NULL;
      if (is_numeric($v)) {
        $values[] = (float) $v;
      }
      else {
        $skipped++;
      }
    }
    if ($values === []) {
      return [[], $skipped, ['no_numeric_values', "Column '$col' had no numeric values."]];
    }
    return [$values, $skipped, NULL];
  }

  /**
   * Median of a pre-sorted, non-empty numeric array.
   */
  private function medianOfSorted(array $sorted): float {
    $n = count($sorted);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
  }

  /**
   * Linear-interpolation percentile (NIST / Excel PERCENTILE.INC).
   */
  private function percentileOfSorted(array $sorted, float $p): float {
    $n = count($sorted);
    if ($n === 1) {
      return $sorted[0];
    }
    $rank = ($p / 100) * ($n - 1);
    $lo = (int) floor($rank);
    $hi = (int) ceil($rank);
    if ($lo === $hi) {
      return $sorted[$lo];
    }
    return $sorted[$lo] + ($rank - $lo) * ($sorted[$hi] - $sorted[$lo]);
  }

  /**
   * Sample variance (Bessel's correction, n-1).
   */
  private function sampleVariance(array $values): float {
    $n = count($values);
    $mean = array_sum($values) / $n;
    $sumSq = 0.0;
    foreach ($values as $v) {
      $d = $v - $mean;
      $sumSq += $d * $d;
    }
    return $sumSq / ($n - 1);
  }

  /**
   * Build a successful result entry, echoing the op's identifying keys.
   */
  private function ok(array $op, mixed $value, int $skipped): array {
    $out = ['op' => $op['type']] + $this->echoOpKeys($op);
    $out['value'] = $value;
    if ($skipped > 0) {
      $out['skipped'] = $skipped;
    }
    return $out;
  }

  /**
   * Build a failed result entry with an error code and human-readable message.
   */
  private function error(array $op, string $code, string $message): array {
    return ['op' => $op['type'] ?? 'unknown'] + $this->echoOpKeys($op) + [
      'error' => $code,
      'message' => $message,
    ];
  }

  /**
   * Subset of the op spec to echo back on each result for traceability.
   */
  private function echoOpKeys(array $op): array {
    $out = [];
    foreach (['column', 'columns', 'p'] as $k) {
      if (array_key_exists($k, $op)) {
        $out[$k] = $op[$k];
      }
    }
    return $out;
  }

}
