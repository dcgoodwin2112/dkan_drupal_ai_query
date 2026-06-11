<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Eval;

use Drupal\dkan_ai_query\Eval\GoldenCaseLoader;
use PHPUnit\Framework\TestCase;

/**
 * Tests GoldenCaseLoader YAML parsing and validation.
 *
 * @group dkan_ai_query
 */
class GoldenCaseLoaderTest extends TestCase {

  /**
   * Path to a throwaway temp YAML file.
   *
   * @var string
   */
  protected string $tmp;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->tmp = tempnam(sys_get_temp_dir(), 'golden_');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @unlink($this->tmp);
  }

  /**
   * A valid YAML file loads into GoldenCase objects.
   */
  public function testLoadsValidYaml(): void {
    file_put_contents($this->tmp, <<<YAML
cases:
  - id: c1
    question: "Q1?"
    expected_dataset_id: abc-123
    expected_columns_used: [a, b]
    expected_answer_pattern: '\\d+'
    expected_refusal: false
    notes: "n"
YAML);
    $cases = (new GoldenCaseLoader())->load($this->tmp);
    $this->assertCount(1, $cases);
    $this->assertSame('c1', $cases[0]->id);
    $this->assertSame('abc-123', $cases[0]->expectedDatasetId);
    $this->assertSame(['a', 'b'], $cases[0]->expectedColumnsUsed);
    $this->assertFalse($cases[0]->expectedRefusal);
  }

  /**
   * Duplicate case ids are rejected.
   */
  public function testRejectsDuplicateIds(): void {
    file_put_contents($this->tmp, <<<YAML
cases:
  - { id: dup, question: "Q?" }
  - { id: dup, question: "Q?" }
YAML);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("Duplicate case id 'dup'");
    (new GoldenCaseLoader())->load($this->tmp);
  }

  /**
   * A case without a question is rejected.
   */
  public function testRejectsMissingQuestion(): void {
    file_put_contents($this->tmp, <<<YAML
cases:
  - id: c1
YAML);
    $this->expectException(\InvalidArgumentException::class);
    (new GoldenCaseLoader())->load($this->tmp);
  }

  /**
   * YAML without a top-level 'cases' list is rejected.
   */
  public function testRejectsMissingCasesKey(): void {
    file_put_contents($this->tmp, "not_cases: []\n");
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("must be a YAML mapping with a 'cases' list");
    (new GoldenCaseLoader())->load($this->tmp);
  }

  /**
   * A missing file path throws.
   */
  public function testRejectsMissingFile(): void {
    $this->expectException(\RuntimeException::class);
    (new GoldenCaseLoader())->load('/nonexistent/path.yml');
  }

  /**
   * Optional fields default to NULL or empty values.
   */
  public function testDefaultsApplied(): void {
    file_put_contents($this->tmp, <<<YAML
cases:
  - id: c1
    question: "Q?"
YAML);
    $cases = (new GoldenCaseLoader())->load($this->tmp);
    $this->assertNull($cases[0]->expectedDatasetId);
    $this->assertSame([], $cases[0]->expectedColumnsUsed);
    $this->assertNull($cases[0]->expectedAnswerPattern);
    $this->assertFalse($cases[0]->expectedRefusal);
    $this->assertNull($cases[0]->expectedFailureCategory);
  }

}
