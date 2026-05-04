<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query_mock\Unit\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\dkan_drupal_ai_query_mock\Service\ScenarioLoader;
use PHPUnit\Framework\TestCase;

/**
 * Unit-tests for ScenarioLoader: YAML discovery and structural validation.
 *
 * @covers \Drupal\dkan_drupal_ai_query_mock\Service\ScenarioLoader
 */
class ScenarioLoaderTest extends TestCase {

  /**
   * Throwaway temp directory simulating a module path with a scenarios/ tree.
   *
   * @var string
   */
  private string $tempModuleDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempModuleDir = sys_get_temp_dir() . '/dkan_aiq_mock_test_' . uniqid('', TRUE);
    mkdir($this->tempModuleDir . '/scenarios', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->tempModuleDir)) {
      foreach (glob($this->tempModuleDir . '/scenarios/*') as $f) {
        unlink($f);
      }
      rmdir($this->tempModuleDir . '/scenarios');
      rmdir($this->tempModuleDir);
    }
    parent::tearDown();
  }

  /**
   * Well-formed YAML round-trips into a populated Scenario.
   */
  public function testParsesValidScenario(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/example.yml', <<<YAML
id: example
description: Sample
match:
  question_contains: [hello]
turns:
  - { type: tool_calls, calls: [{ name: search_datasets, args: { keyword: "x" } }] }
  - { type: final_answer, content: "done" }
YAML);
    $loader = $this->buildLoader();
    $scenarios = $loader->all();

    $this->assertArrayHasKey('example', $scenarios);
    $this->assertSame('example', $scenarios['example']->id);
    $this->assertSame('Sample', trim($scenarios['example']->description));
    $this->assertSame(['hello'], $scenarios['example']->match['question_contains']);
    $this->assertCount(2, $scenarios['example']->turns);
    $this->assertSame('tool_calls', $scenarios['example']->turns[0]['type']);
    $this->assertSame('final_answer', $scenarios['example']->turns[1]['type']);
  }

  /**
   * The YAML stem is used when the file has no explicit `id` key.
   */
  public function testFallsBackToFilenameWhenIdMissing(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/from_filename.yml', <<<YAML
turns:
  - { type: final_answer, content: "ok" }
YAML);
    $loader = $this->buildLoader();
    $scenarios = $loader->all();

    $this->assertArrayHasKey('from_filename', $scenarios);
    $this->assertSame('from_filename', $scenarios['from_filename']->id);
  }

  /**
   * A scenario with no `turns` key fails loudly at load time.
   */
  public function testFailsLoudOnMissingTurns(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/no_turns.yml', <<<YAML
id: no_turns
description: missing turns
YAML);
    $loader = $this->buildLoader();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/no_turns\.yml has no turns/');
    $loader->all();
  }

  /**
   * A turn with an unknown type fails loudly with a clear message.
   */
  public function testFailsLoudOnUnknownTurnType(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/bad.yml', <<<YAML
id: bad
turns:
  - { type: invalid_kind }
YAML);
    $loader = $this->buildLoader();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/unknown type "invalid_kind"/');
    $loader->all();
  }

  /**
   * A tool_calls turn with an empty `calls` array fails loudly.
   */
  public function testFailsLoudOnEmptyToolCalls(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/empty_calls.yml', <<<YAML
id: empty_calls
turns:
  - { type: tool_calls, calls: [] }
YAML);
    $loader = $this->buildLoader();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/needs a non-empty "calls" array/');
    $loader->all();
  }

  /**
   * A final_answer turn missing `content` fails loudly.
   */
  public function testFailsLoudOnFinalAnswerWithoutContent(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/no_content.yml', <<<YAML
id: no_content
turns:
  - type: final_answer
YAML);
    $loader = $this->buildLoader();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/needs a "content" string/');
    $loader->all();
  }

  /**
   * A tool call missing `name` fails loudly.
   */
  public function testFailsLoudOnToolCallWithoutName(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/missing_name.yml', <<<YAML
id: missing_name
turns:
  - { type: tool_calls, calls: [{ args: {} }] }
YAML);
    $loader = $this->buildLoader();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/has no tool name/');
    $loader->all();
  }

  /**
   * Calling reset() picks up newly added YAML files on the next read.
   */
  public function testResetForcesRediscovery(): void {
    file_put_contents($this->tempModuleDir . '/scenarios/v1.yml', <<<YAML
id: v1
turns:
  - { type: final_answer, content: "first" }
YAML);
    $loader = $this->buildLoader();
    $this->assertCount(1, $loader->all());

    file_put_contents($this->tempModuleDir . '/scenarios/v2.yml', <<<YAML
id: v2
turns:
  - { type: final_answer, content: "second" }
YAML);
    $this->assertCount(1, $loader->all(), 'snapshot still cached pre-reset');

    $loader->reset();
    $this->assertCount(2, $loader->all());
  }

  /**
   * Wires a ScenarioLoader pointed at the per-test temp module dir.
   */
  private function buildLoader(): ScenarioLoader {
    $resolver = $this->createMock(ExtensionPathResolver::class);
    $resolver->method('getPath')->willReturn($this->tempModuleDir);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    return new ScenarioLoader($resolver, $cache);
  }

}
