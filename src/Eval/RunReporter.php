<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Eval;

/**
 * Writes JSONL machine output and a Markdown human summary for an eval run.
 */
class RunReporter {

  /**
   * Write JSONL and Markdown reports for an eval run.
   *
   * @param \Drupal\dkan_drupal_ai_query\Eval\CaseResult[] $results
   *   Results to serialize.
   * @param string $outputDir
   *   Directory to write into. Created if missing.
   * @param string $runLabel
   *   Filename label, used as run-{label}.{jsonl,md}.
   *
   * @return array{jsonl: string, markdown: string}
   *   Absolute paths of the two output files.
   */
  public function write(array $results, string $outputDir, string $runLabel): array {
    if (!is_dir($outputDir) && !mkdir($outputDir, 0755, TRUE) && !is_dir($outputDir)) {
      throw new \RuntimeException("Cannot create output directory: {$outputDir}");
    }
    $jsonlPath = "{$outputDir}/run-{$runLabel}.jsonl";
    $mdPath = "{$outputDir}/run-{$runLabel}.md";

    $handle = fopen($jsonlPath, 'w');
    if ($handle === FALSE) {
      throw new \RuntimeException("Cannot write JSONL: {$jsonlPath}");
    }
    foreach ($results as $r) {
      fwrite($handle, json_encode($r->toArray(), JSON_UNESCAPED_SLASHES) . "\n");
    }
    fclose($handle);

    file_put_contents($mdPath, $this->renderMarkdown($results, $runLabel));

    return ['jsonl' => $jsonlPath, 'markdown' => $mdPath];
  }

  /**
   * Compute aggregate counts and rates for a set of results.
   *
   * @param \Drupal\dkan_drupal_ai_query\Eval\CaseResult[] $results
   *   Results to summarize.
   *
   * @return array
   *   Keys: total, pass, fail, pass_rate, dsl_limitation_rate,
   *   by_failure_category.
   */
  public function summarize(array $results): array {
    $total = count($results);
    $pass = 0;
    $byCategory = [];
    foreach ($results as $r) {
      if ($r->outcome === CaseResult::OUTCOME_PASS) {
        $pass++;
        continue;
      }
      $key = $r->failureCategory ?? 'uncategorized';
      $byCategory[$key] = ($byCategory[$key] ?? 0) + 1;
    }
    $passRate = $total > 0 ? $pass / $total : 0.0;
    $dslLimitationRate = $total > 0 ? (($byCategory['dsl_limitation'] ?? 0) / $total) : 0.0;
    return [
      'total' => $total,
      'pass' => $pass,
      'fail' => $total - $pass,
      'pass_rate' => $passRate,
      'dsl_limitation_rate' => $dslLimitationRate,
      'by_failure_category' => $byCategory,
    ];
  }

  /**
   * Render the human-readable Markdown summary.
   *
   * @param \Drupal\dkan_drupal_ai_query\Eval\CaseResult[] $results
   *   Results to render.
   * @param string $runLabel
   *   Run label, used in the document title.
   */
  protected function renderMarkdown(array $results, string $runLabel): string {
    $summary = $this->summarize($results);
    $lines = [];
    $lines[] = "# Eval run {$runLabel}";
    $lines[] = '';
    $lines[] = "- Total cases: {$summary['total']}";
    $lines[] = "- Pass: {$summary['pass']}";
    $lines[] = "- Fail: {$summary['fail']}";
    $lines[] = sprintf('- Pass rate: %.1f%%', $summary['pass_rate'] * 100);
    $lines[] = sprintf('- DSL limitation rate: %.1f%%', $summary['dsl_limitation_rate'] * 100);
    $lines[] = '';
    if (!empty($summary['by_failure_category'])) {
      $lines[] = '## Failures by category';
      $lines[] = '';
      $lines[] = '| Category | Count |';
      $lines[] = '|---|---|';
      foreach ($summary['by_failure_category'] as $cat => $count) {
        $lines[] = "| {$cat} | {$count} |";
      }
      $lines[] = '';
    }
    $lines[] = '## Cases';
    $lines[] = '';
    $lines[] = '| ID | Outcome | Category | Duration (ms) |';
    $lines[] = '|---|---|---|---|';
    foreach ($results as $r) {
      $cat = $r->failureCategory ?? '';
      $lines[] = "| {$r->caseId} | {$r->outcome} | {$cat} | {$r->durationMs} |";
    }
    return implode("\n", $lines) . "\n";
  }

}
