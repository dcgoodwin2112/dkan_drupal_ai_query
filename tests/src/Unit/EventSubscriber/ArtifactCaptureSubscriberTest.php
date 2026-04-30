<?php

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\datastore\DatastoreService;
use Drupal\dkan_drupal_ai_query\EventSubscriber\ArtifactCaptureSubscriber;
use Drupal\dkan_drupal_ai_query\Service\ArtifactStorage;
use Drupal\dkan_drupal_ai_query\Service\RefusalCollector;
use Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArtifactCaptureSubscriberTest extends TestCase {

  /**
   * Capture the first append() call whose entry has the given $type.
   *
   * Every onToolFinished now also writes a `tool_call` debug snapshot, so
   * tests for the data/chart/refusal paths need to filter out that extra
   * record. Returns a reference into which the matched entry is stored.
   */
  protected function bindCaptureByType(ArtifactStorage $artifacts, string $type, &$out): void {
    $artifacts->method('append')
      ->willReturnCallback(function (string $tid, array $entry) use (&$out, $type) {
        if ($entry['type'] === $type && $out === NULL) {
          $out = $entry;
        }
      });
  }

  protected function buildSubscriber(
    ArtifactStorage $artifacts,
    ?ResourceIdResolver $resolver = NULL,
    ?RefusalCollector $refusals = NULL,
  ): ArtifactCaptureSubscriber {
    $resolver = $resolver ?? $this->createMock(ResourceIdResolver::class);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    return new ArtifactCaptureSubscriber(
      $artifacts,
      $this->createMock(LoggerInterface::class),
      $resolver,
      $this->createMock(DatastoreService::class),
      $refusals ?? new RefusalCollector(),
      $configFactory,
    );
  }

  /**
   * Regression for finding #1.
   *
   * DatastoreTools::queryDatastore returns total_rows; the captured artifact
   * must propagate that into its `count` field so the UI can render the true
   * total. If this test fails, the table summary will fall back to row-count.
   */
  public function testCaptureDataReadsTotalRows(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $resolver = $this->createMock(ResourceIdResolver::class);
    $resolver->method('resolve')->willReturnArgument(0);
    $resolver->method('resolveDistributionUuid')->willReturn(NULL);

    $subscriber = $this->buildSubscriber($artifacts, $resolver);

    $tool = new \FunctionCallStub(
      'query_datastore',
      json_encode([
        'results' => [['a' => 1], ['a' => 2], ['a' => 3]],
        'total_rows' => 1234,
      ]),
      ['resource_id' => 'rid__1', 'limit' => 3],
    );

    $event = new AgentToolFinishedExecutionEvent('thread-x', $tool);
    $subscriber->onToolFinished($event);

    $this->assertSame('data', $captured['type']);
    $this->assertSame('query_datastore', $captured['tool']);
    $this->assertCount(3, $captured['rows']);
    $this->assertSame(1234, $captured['count'], 'Artifact count must reflect total_rows from queryDatastore');
  }

  public function testCaptureDataIgnoresErrorOutput(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    // Even when the tool errors, the debug-panel snapshot still goes through
    // so the user can see the failed call. The data artifact must be skipped.
    $appended = [];
    $artifacts->method('append')
      ->willReturnCallback(function (string $tid, array $entry) use (&$appended) {
        $appended[] = $entry;
      });

    $tool = new \FunctionCallStub(
      'query_datastore',
      json_encode(['error' => 'Could not resolve resource: foo']),
      ['resource_id' => 'foo'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $types = array_column($appended, 'type');
    $this->assertNotContains('data', $types, 'Error output must not produce a data artifact');
    $this->assertContains('tool_call', $types, 'Tool-call debug snapshot is captured even on error');
  }

  public function testCaptureChartParsesJsonString(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'chart', $captured);

    $spec = [
      '$schema' => 'https://vega.github.io/schema/vega-lite/v5.json',
      'data' => ['values' => [['x' => 'A', 'y' => 1]]],
      'mark' => 'bar',
    ];
    $tool = new \FunctionCallStub(
      'create_chart',
      json_encode(['status' => 'chart_rendered']),
      ['spec' => json_encode($spec)],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('chart', $captured['type']);
    $this->assertSame('bar', $captured['spec']['mark']);
  }

  public function testNoDomainArtifactForUnrelatedTools(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $appended = [];
    $artifacts->method('append')
      ->willReturnCallback(function (string $tid, array $entry) use (&$appended) {
        $appended[] = $entry;
      });

    $tool = new \FunctionCallStub(
      'list_datasets',
      json_encode(['datasets' => []]),
      [],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    // List/search/etc. tools don't produce a data, chart, or refusal artifact —
    // only the tool_call snapshot used by the debug panel.
    $types = array_column($appended, 'type');
    $this->assertNotContains('data', $types);
    $this->assertNotContains('chart', $types);
    $this->assertNotContains('refusal', $types);
    $this->assertSame(['tool_call'], $types);
  }

  public function testCaptureDataAttachesProvenanceBlock(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $resolver = $this->createMock(ResourceIdResolver::class);
    $resolver->method('resolve')->willReturnArgument(0);
    $resolver->method('resolveDistributionUuid')->willReturn(NULL);

    $subscriber = $this->buildSubscriber($artifacts, $resolver);

    $tool = new \FunctionCallStub(
      'query_datastore',
      json_encode([
        'results' => [['city' => 'Houston', 'violent_crimes' => 22008]],
        'total_rows' => 1,
        'sanity_flags' => [
          'zero_rows' => FALSE,
          'all_null_columns' => [],
          'row_cap_hit' => FALSE,
          'coverage_warning' => NULL,
        ],
      ]),
      [
        'resource_id' => 'rid__1',
        'columns' => 'city,violent_crimes',
        'conditions' => json_encode([['property' => 'city', 'value' => 'Houston', 'operator' => '=']]),
        'limit' => 100,
      ],
    );

    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertArrayHasKey('provenance', $captured);
    $prov = $captured['provenance'];
    $this->assertSame('query_datastore', $prov['tool']);
    $this->assertSame(1, $prov['row_count']);
    $this->assertSame(1, $prov['total_rows']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $prov['executed_at']);
    $this->assertSame('rid__1', $prov['query_summary']['resource_id']);
    $this->assertSame('city,violent_crimes', $prov['query_summary']['columns']);
    $this->assertSame(100, $prov['query_summary']['limit']);
    // Conditions are decoded from JSON so the UI / judge can read structure.
    $this->assertIsArray($prov['query_summary']['conditions']);
    $this->assertSame('Houston', $prov['query_summary']['conditions'][0]['value']);
    $this->assertSame(['zero_rows' => FALSE, 'all_null_columns' => [], 'row_cap_hit' => FALSE, 'coverage_warning' => NULL], $prov['sanity_flags']);
  }

  public function testProvenanceForJoinIncludesJoinFields(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $resolver = $this->createMock(ResourceIdResolver::class);
    $resolver->method('resolve')->willReturnArgument(0);
    $resolver->method('resolveDistributionUuid')->willReturn(NULL);

    $subscriber = $this->buildSubscriber($artifacts, $resolver);

    $tool = new \FunctionCallStub(
      'query_datastore_join',
      json_encode(['results' => [['x' => 1]], 'total_rows' => 1]),
      [
        'resource_id' => 'rid__1',
        'join_resource_id' => 'rid__2',
        'join_on' => 'state=state',
        'limit' => 50,
      ],
    );

    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $summary = $captured['provenance']['query_summary'];
    $this->assertSame('rid__1', $summary['resource_id']);
    $this->assertSame('rid__2', $summary['join_resource_id']);
    $this->assertSame('state=state', $summary['join_on']);
  }

  public function testProvenanceSurfacesSanityFlagsWhenSet(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $resolver = $this->createMock(ResourceIdResolver::class);
    $resolver->method('resolve')->willReturnArgument(0);
    $resolver->method('resolveDistributionUuid')->willReturn(NULL);

    $subscriber = $this->buildSubscriber($artifacts, $resolver);

    $tool = new \FunctionCallStub(
      'query_datastore',
      json_encode([
        'results' => [],
        'total_rows' => 0,
        'sanity_flags' => [
          'zero_rows' => TRUE,
          'all_null_columns' => [],
          'row_cap_hit' => FALSE,
          'coverage_warning' => 'Filter on date-like column(s) [year] returned 0 rows — verify coverage.',
        ],
      ]),
      ['resource_id' => 'rid__1'],
    );

    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertTrue($captured['provenance']['sanity_flags']['zero_rows']);
    $this->assertStringContainsString('coverage', $captured['provenance']['sanity_flags']['coverage_warning']);
  }

}
