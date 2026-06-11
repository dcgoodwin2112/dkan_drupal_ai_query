<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Eval;

use Drupal\dkan_ai_query\Eval\CaseResult;
use Drupal\dkan_ai_query\Eval\RunReporter;
use PHPUnit\Framework\TestCase;

class RunReporterTest extends TestCase {

  protected string $tmpDir;

  protected function setUp(): void {
    $this->tmpDir = sys_get_temp_dir() . '/eval_reporter_' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir);
  }

  protected function tearDown(): void {
    foreach (glob($this->tmpDir . '/*') as $f) {
      @unlink($f);
    }
    @rmdir($this->tmpDir);
  }

  protected function makeResult(string $id, string $outcome, ?string $cat = NULL): CaseResult {
    return new CaseResult(
      caseId: $id,
      question: "Q for {$id}",
      outcome: $outcome,
      failureCategory: $cat,
      answer: 'A',
      toolCalls: [],
      artifacts: [],
      durationMs: 100,
      provider: 'anthropic',
      model: 'claude-haiku-4-5-20251001',
      executedAt: '2026-04-29T00:00:00+00:00',
    );
  }

  public function testSummarizeCountsAndRates(): void {
    $results = [
      $this->makeResult('a', CaseResult::OUTCOME_PASS),
      $this->makeResult('b', CaseResult::OUTCOME_PASS),
      $this->makeResult('c', CaseResult::OUTCOME_FAIL, 'dsl_limitation'),
      $this->makeResult('d', CaseResult::OUTCOME_FAIL, 'wrong_summary'),
    ];
    $summary = (new RunReporter())->summarize($results);
    $this->assertSame(4, $summary['total']);
    $this->assertSame(2, $summary['pass']);
    $this->assertSame(2, $summary['fail']);
    $this->assertEqualsWithDelta(0.5, $summary['pass_rate'], 0.0001);
    $this->assertEqualsWithDelta(0.25, $summary['dsl_limitation_rate'], 0.0001);
    $this->assertSame(['dsl_limitation' => 1, 'wrong_summary' => 1], $summary['by_failure_category']);
  }

  public function testWriteProducesJsonlAndMarkdown(): void {
    $results = [$this->makeResult('a', CaseResult::OUTCOME_PASS)];
    $paths = (new RunReporter())->write($results, $this->tmpDir, 'test');

    $this->assertFileExists($paths['jsonl']);
    $this->assertFileExists($paths['markdown']);

    $jsonl = file_get_contents($paths['jsonl']);
    $line = trim($jsonl);
    $decoded = json_decode($line, TRUE);
    $this->assertSame('a', $decoded['case_id']);
    $this->assertSame('pass', $decoded['outcome']);

    $md = file_get_contents($paths['markdown']);
    $this->assertStringContainsString('# Eval run test', $md);
    $this->assertStringContainsString('Pass rate: 100.0%', $md);
  }

  public function testEmptyResultsHandledCleanly(): void {
    $summary = (new RunReporter())->summarize([]);
    $this->assertSame(0, $summary['total']);
    $this->assertSame(0.0, $summary['pass_rate']);
    $this->assertSame(0.0, $summary['dsl_limitation_rate']);
  }

}
