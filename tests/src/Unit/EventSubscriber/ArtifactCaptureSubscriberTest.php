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

    // A tool name that's not in any capture group: no data, chart,
    // refusal, or aux_tool artifact — only the tool_call snapshot.
    $tool = new \FunctionCallStub(
      'unknown_tool',
      json_encode(['some' => 'payload']),
      [],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $types = array_column($appended, 'type');
    $this->assertNotContains('data', $types);
    $this->assertNotContains('chart', $types);
    $this->assertNotContains('refusal', $types);
    $this->assertNotContains('aux_tool', $types);
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

  /**
   * Sample_rows pass-through: rows arrive intact, provenance has no query.
   */
  public function testCaptureSimpleDataSampleRows(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $tool = new \FunctionCallStub(
      'sample_rows',
      json_encode([
        'resource_id' => 'rid__1',
        'rows' => [
          ['city' => 'Houston', 'pop' => 2300000],
          ['city' => 'Austin', 'pop' => 950000],
        ],
        'row_count' => 2,
      ]),
      ['resource_id' => 'rid__1'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('data', $captured['type']);
    $this->assertSame('sample_rows', $captured['tool']);
    $this->assertCount(2, $captured['rows']);
    $this->assertSame(2, $captured['count']);
    $this->assertNull($captured['columns_hint']);
    $this->assertArrayHasKey('provenance', $captured);
    $this->assertArrayNotHasKey('query_summary', $captured['provenance']);
  }

  /**
   * Distinct_values now lands in the supporting-data panel as an aux_tool.
   *
   * It used to be a primary-table SIMPLE_TABLE_TOOL, but the agent uses it
   * as a filter-discovery step — almost always plumbing — so it moved.
   */
  public function testCaptureAuxToolDistinctValues(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'aux_tool', $captured);

    $tool = new \FunctionCallStub(
      'distinct_values',
      json_encode([
        'resource_id' => 'rid__1',
        'column' => 'state',
        'values' => ['TX', 'CA', 'NY'],
        'value_count' => 3,
        'truncated' => FALSE,
      ]),
      ['resource_id' => 'rid__1', 'column' => 'state'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('aux_tool', $captured['type']);
    $this->assertSame('distinct_values', $captured['tool']);
    $this->assertSame("3 distinct values for 'state'", $captured['structured']['headline']);
    $this->assertSame('state', $captured['structured']['column']);
    $this->assertFalse($captured['structured']['truncated']);
    $this->assertSame(['TX', 'CA', 'NY'], $captured['structured']['values']);
  }

  /**
   * Search_columns: matches array passes through with explicit column order.
   */
  public function testCaptureSimpleDataSearchColumns(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $tool = new \FunctionCallStub(
      'search_columns',
      json_encode([
        'matches' => [
          [
            'dataset_title' => 'Crime Stats',
            'dataset_uuid' => 'd-1',
            'resource_id' => 'rid__1',
            'column_name' => 'date_reported',
            'column_type' => 'date',
            'matched_in' => 'name',
          ],
        ],
        'total_matches' => 1,
      ]),
      ['query' => 'date'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('search_columns', $captured['tool']);
    $this->assertCount(1, $captured['rows']);
    $this->assertSame(1, $captured['count']);
    $this->assertSame(
      ['dataset_title', 'resource_id', 'column_name', 'column_type', 'matched_in'],
      $captured['columns_hint']
    );
  }

  /**
   * List_datasets reshape: distributions array becomes an integer count cell.
   */
  public function testCaptureSimpleDataListDatasetsReshape(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $tool = new \FunctionCallStub(
      'list_datasets',
      json_encode([
        'datasets' => [
          [
            'identifier' => 'd-1',
            'title' => 'Crime Stats',
            'description' => 'Annual reports',
            'distributions' => [['x' => 1], ['x' => 2]],
          ],
          [
            'identifier' => 'd-2',
            'title' => 'Budget',
            'description' => 'City spending',
            'distributions' => [],
          ],
        ],
        'total' => 2,
        'offset' => 0,
        'limit' => 50,
      ]),
      [],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('list_datasets', $captured['tool']);
    $this->assertCount(2, $captured['rows']);
    $this->assertSame(2, $captured['rows'][0]['distributions'], 'distributions cell should be the count, not the array');
    $this->assertSame(0, $captured['rows'][1]['distributions']);
    $this->assertSame(2, $captured['count']);
  }

  /**
   * List_distributions: count_key is NULL, so rows are counted directly.
   */
  public function testCaptureSimpleDataListDistributions(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $tool = new \FunctionCallStub(
      'list_distributions',
      json_encode([
        'distributions' => [
          ['identifier' => 'd-1', 'resource_id' => 'rid__1', 'title' => 'CSV', 'mediaType' => 'text/csv'],
          ['identifier' => 'd-2', 'resource_id' => 'rid__2', 'title' => 'JSON', 'mediaType' => 'application/json'],
        ],
      ]),
      ['dataset_id' => 'd-1'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('list_distributions', $captured['tool']);
    $this->assertCount(2, $captured['rows']);
    $this->assertSame(2, $captured['count']);
    $this->assertSame(['identifier', 'resource_id', 'title', 'mediaType'], $captured['columns_hint']);
  }

  /**
   * Get_datastore_schema now lands in supporting-data, not as a primary table.
   *
   * Schema is the agent's planning input — almost never the user's direct
   * ask — so it moved from SIMPLE_TABLE_TOOLS to AUX_TOOLS.
   */
  public function testCaptureAuxToolGetDatastoreSchema(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'aux_tool', $captured);

    $tool = new \FunctionCallStub(
      'get_datastore_schema',
      json_encode([
        'resource_id' => 'rid__1',
        'columns' => [
          ['name' => 'city', 'type' => 'text', 'description' => 'City name'],
          ['name' => 'population', 'type' => 'int', 'description' => 'Total population'],
        ],
      ]),
      ['resource_id' => 'rid__1'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('aux_tool', $captured['type']);
    $this->assertSame('get_datastore_schema', $captured['tool']);
    $this->assertSame('2 columns', $captured['structured']['headline']);
    $this->assertCount(2, $captured['structured']['columns']);
    $this->assertSame('city', $captured['structured']['columns'][0]['name']);
    $this->assertSame('text', $captured['structured']['columns'][0]['type']);
  }

  /**
   * Regression gate: simple-table tools never emit a query_summary block.
   */
  public function testSimpleToolsDoNotEmitQuerySummary(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'data', $captured);

    $tool = new \FunctionCallStub(
      'sample_rows',
      json_encode(['rows' => [['x' => 1]], 'row_count' => 1]),
      ['resource_id' => 'rid__1', 'limit' => 5],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertArrayNotHasKey('query_summary', $captured['provenance']);
  }

  /**
   * Compute_stats: quartile object normalises to "q1=…, q2=…, q3=…, IQR=…".
   */
  public function testCaptureAuxToolComputeStats(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'aux_tool', $captured);

    $tool = new \FunctionCallStub(
      'compute_stats',
      json_encode([
        'row_count' => 100,
        'results' => [
          ['op' => 'median', 'column' => 'pop', 'value' => 4.2],
          ['op' => 'quartiles', 'column' => 'pop', 'value' => ['q1' => 10, 'q2' => 20, 'q3' => 30, 'iqr' => 20], 'rows_skipped' => 3],
        ],
        'warnings' => ['Computed on rows you passed in.'],
      ]),
      [],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('aux_tool', $captured['type']);
    $this->assertSame('compute_stats', $captured['tool']);
    $this->assertSame('2 statistics computed across 100 rows', $captured['structured']['headline']);
    $this->assertSame(['Computed on rows you passed in.'], $captured['structured']['warnings']);
    $this->assertCount(2, $captured['structured']['rows']);
    // Operation must be read from `op` (the field ComputeStatsTool emits).
    $this->assertSame('median', $captured['structured']['rows'][0]['operation']);
    $this->assertSame('quartiles', $captured['structured']['rows'][1]['operation']);
    $this->assertSame('4.2', $captured['structured']['rows'][0]['value']);
    $this->assertSame('q1=10, q2=20, q3=30, IQR=20', $captured['structured']['rows'][1]['value']);
    $this->assertSame(3, $captured['structured']['rows'][1]['rows_skipped']);
    // Raw payload preserved for the power-user disclosure.
    $this->assertSame(100, $captured['raw']['row_count']);
  }

  /**
   * Get_data_dictionary: nested map flattens to indexed array of dicts.
   */
  public function testCaptureAuxToolDataDictionary(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'aux_tool', $captured);

    $tool = new \FunctionCallStub(
      'get_data_dictionary',
      json_encode([
        'dictionaries' => [
          'rid__1' => [
            'identifier' => 'd-1',
            'url' => 'https://example.org/dict-1',
            'title' => 'Crime Dictionary',
            'fields' => [
              ['name' => 'city', 'title' => 'City', 'type' => 'string', 'description' => 'Place'],
              ['name' => 'count', 'title' => 'Count', 'type' => 'integer', 'description' => 'Cases'],
            ],
          ],
          'rid__2' => [
            'identifier' => 'd-2',
            'url' => '',
            'title' => 'Budget Dictionary',
            'fields' => [
              ['name' => 'year', 'title' => 'Year', 'type' => 'integer', 'description' => ''],
            ],
          ],
        ],
      ]),
      [],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('get_data_dictionary', $captured['tool']);
    $this->assertSame('3 field definitions across 2 resources', $captured['structured']['headline']);
    $this->assertCount(2, $captured['structured']['dictionaries']);
    $this->assertSame('Crime Dictionary', $captured['structured']['dictionaries'][0]['title']);
    $this->assertSame('rid__1', $captured['structured']['dictionaries'][0]['resource_id']);
    $this->assertCount(2, $captured['structured']['dictionaries'][0]['fields']);
    $this->assertSame('city', $captured['structured']['dictionaries'][0]['fields'][0]['name']);
  }

  /**
   * Get_datastore_stats: columns pass through, headline names both totals.
   */
  public function testCaptureAuxToolDatastoreStats(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $this->bindCaptureByType($artifacts, 'aux_tool', $captured);

    $tool = new \FunctionCallStub(
      'get_datastore_stats',
      json_encode([
        'resource_id' => 'rid__1',
        'total_rows' => 12400,
        'columns' => [
          ['name' => 'city', 'type' => 'string', 'null_count' => 0, 'distinct_count' => 50, 'min' => 'Austin', 'max' => 'Yorba'],
          ['name' => 'pop', 'type' => 'integer', 'null_count' => 12, 'distinct_count' => 4000, 'min' => 1, 'max' => 9000000],
        ],
      ]),
      ['resource_id' => 'rid__1'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('get_datastore_stats', $captured['tool']);
    $this->assertSame('Stats for 2 columns in a table of 12400 rows', $captured['structured']['headline']);
    $this->assertSame(12400, $captured['structured']['total_rows']);
    $this->assertCount(2, $captured['structured']['columns']);
    $this->assertSame('city', $captured['structured']['columns'][0]['name']);
    $this->assertSame(50, $captured['structured']['columns'][0]['distinct_count']);
  }

  /**
   * Aux tool error payloads must skip the aux artifact (debug snapshot only).
   */
  public function testAuxToolErrorOutputSkipped(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $appended = [];
    $artifacts->method('append')
      ->willReturnCallback(function (string $tid, array $entry) use (&$appended) {
        $appended[] = $entry;
      });

    $tool = new \FunctionCallStub(
      'compute_stats',
      json_encode(['error' => 'invalid_operation', 'message' => 'unknown type']),
      [],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $types = array_column($appended, 'type');
    $this->assertNotContains('aux_tool', $types, 'Error output must not produce an aux_tool artifact');
    $this->assertContains('tool_call', $types, 'Tool-call debug snapshot is captured even on error');
  }

}
