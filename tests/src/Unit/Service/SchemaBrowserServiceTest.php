<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\Service;

use Drupal\dkan_drupal_ai_query\Service\DatasetCaveatRegistry;
use Drupal\dkan_drupal_ai_query\Service\SchemaBrowserService;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_query_tools\Tool\SearchTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for SchemaBrowserService.
 *
 * Stubs the four collaborating services (MetastoreTools, DatastoreTools,
 * SearchTools, DatasetCaveatRegistry) and asserts that the browse-shaped
 * payloads come out the way the BrowseController consumes them.
 *
 * @covers \Drupal\dkan_drupal_ai_query\Service\SchemaBrowserService
 */
class SchemaBrowserServiceTest extends TestCase {

  /**
   * Empty `q` routes through MetastoreTools::listDatasets and tags caveats.
   */
  public function testListDatasetsEmptyQueryRoutesThroughMetastore(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->once())
      ->method('listDatasets')
      ->with(0, 25)
      ->willReturn([
        'datasets' => [
          ['identifier' => 'a-1', 'title' => 'A', 'distributions' => 1],
          ['identifier' => 'b-2', 'title' => 'B', 'distributions' => 2],
        ],
        'total' => 2,
        'offset' => 0,
        'limit' => 25,
      ]);

    $caveats = $this->createMock(DatasetCaveatRegistry::class);
    $caveats->method('listDatasets')->willReturn(['a-1']);

    $search = $this->createMock(SearchTools::class);
    $search->expects($this->never())->method('searchDatasets');

    $service = new SchemaBrowserService(
      $metastore,
      $this->createMock(DatastoreTools::class),
      $search,
      $caveats,
      new NullLogger(),
    );
    $result = $service->listDatasets(0, 25, '');

    $this->assertSame('', $result['q']);
    $this->assertSame(2, $result['total']);
    $this->assertSame(0, $result['offset']);
    $this->assertSame(25, $result['limit']);
    $this->assertCount(2, $result['datasets']);
    $this->assertTrue($result['datasets'][0]['has_caveats']);
    $this->assertFalse($result['datasets'][1]['has_caveats']);
  }

  /**
   * Non-empty `q` routes through SearchTools and normalizes the shape.
   */
  public function testListDatasetsWithQueryRoutesThroughSearch(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->expects($this->never())->method('listDatasets');

    $search = $this->createMock(SearchTools::class);
    // offset=0, limit=25 → page=1, pageSize=25 (clamped at 50 elsewhere).
    $search->expects($this->once())
      ->method('searchDatasets')
      ->with('parks', 1, 25)
      ->willReturn([
        'results' => [
          ['identifier' => 'parks-uuid', 'title' => 'Parks', 'distributions' => 1],
        ],
        'total' => 1,
        'page' => 1,
        'page_size' => 25,
      ]);

    $caveats = $this->createMock(DatasetCaveatRegistry::class);
    $caveats->method('listDatasets')->willReturn([]);

    $service = new SchemaBrowserService(
      $metastore,
      $this->createMock(DatastoreTools::class),
      $search,
      $caveats,
      new NullLogger(),
    );
    $result = $service->listDatasets(0, 25, 'parks');

    $this->assertSame('parks', $result['q']);
    $this->assertSame(1, $result['total']);
    $this->assertCount(1, $result['datasets']);
    // Search result key normalized from `results` to `datasets`.
    $this->assertSame('parks-uuid', $result['datasets'][0]['identifier']);
  }

  /**
   * Search-API page-size cap (50) is honored when caller asks for more.
   */
  public function testListDatasetsSearchPathClampsLimitToFifty(): void {
    $search = $this->createMock(SearchTools::class);
    $search->expects($this->once())
      ->method('searchDatasets')
      ->with('x', 1, 50)
      ->willReturn(['results' => [], 'total' => 0, 'page' => 1, 'page_size' => 50]);

    $caveats = $this->createMock(DatasetCaveatRegistry::class);
    $caveats->method('listDatasets')->willReturn([]);

    $service = new SchemaBrowserService(
      $this->createMock(MetastoreTools::class),
      $this->createMock(DatastoreTools::class),
      $search,
      $caveats,
      new NullLogger(),
    );
    $result = $service->listDatasets(0, 100, 'x');
    $this->assertSame(50, $result['limit']);
  }

  /**
   * Search-API errors propagate to the caller so the controller can 5xx.
   */
  public function testListDatasetsPropagatesSearchError(): void {
    $search = $this->createMock(SearchTools::class);
    $search->method('searchDatasets')->willReturn(['error' => 'Search failed: timeout']);
    $caveats = $this->createMock(DatasetCaveatRegistry::class);

    $service = new SchemaBrowserService(
      $this->createMock(MetastoreTools::class),
      $this->createMock(DatastoreTools::class),
      $search,
      $caveats,
      new NullLogger(),
    );
    $result = $service->listDatasets(0, 25, 'parks');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('timeout', $result['error']);
  }

  /**
   * Dataset(uuid) merges caveats and tags has_dictionary on each distribution.
   */
  public function testDatasetMergesCaveatsAndDistributions(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')->willReturn([
      'dataset' => [
        'identifier' => 'abc-123',
        'title' => 'Test Dataset',
        'description' => 'A description.',
        'theme' => ['Public'],
        'modified' => '2024-01-01',
      ],
    ]);
    $metastore->method('listDistributions')->willReturn([
      'distributions' => [
        [
          'identifier' => 'dist-1',
          'resource_id' => 'rid__v1',
          'title' => 'CSV',
          'mediaType' => 'text/csv',
          'describedBy' => 'https://example.test/dict',
        ],
        [
          'identifier' => 'dist-2',
          'resource_id' => 'rid2__v1',
          'mediaType' => 'application/json',
        ],
      ],
    ]);

    $caveats = $this->createMock(DatasetCaveatRegistry::class);
    $caveats->expects($this->once())
      ->method('attach')
      ->willReturnCallback(static fn (array $payload, string $uuid) => $payload + ['caveats' => ['suppression' => 'Counts <10 suppressed.']]);

    $service = new SchemaBrowserService(
      $metastore,
      $this->createMock(DatastoreTools::class),
      $this->createMock(SearchTools::class),
      $caveats,
      new NullLogger(),
    );
    $result = $service->dataset('abc-123');

    $this->assertSame('abc-123', $result['identifier']);
    $this->assertSame('Test Dataset', $result['title']);
    $this->assertSame(['Public'], $result['theme']);
    $this->assertCount(2, $result['distributions']);
    $this->assertTrue($result['distributions'][0]['has_dictionary']);
    $this->assertFalse($result['distributions'][1]['has_dictionary']);
    $this->assertSame(['suppression' => 'Counts <10 suppressed.'], $result['caveats']);
  }

  /**
   * Dataset(uuid) omits caveats when the registry doesn't add the key.
   *
   * Mirrors DatasetCaveatRegistry::attach() discipline — no record / blank
   * record results in no `caveats` key on the payload.
   */
  public function testDatasetOmitsCaveatsWhenRegistryDoesNotAttach(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')->willReturn([
      'dataset' => ['identifier' => 'abc-123', 'title' => 'X'],
    ]);
    $metastore->method('listDistributions')->willReturn(['distributions' => []]);

    $caveats = $this->createMock(DatasetCaveatRegistry::class);
    $caveats->method('attach')->willReturnCallback(static fn (array $p) => $p);

    $service = new SchemaBrowserService(
      $metastore,
      $this->createMock(DatastoreTools::class),
      $this->createMock(SearchTools::class),
      $caveats,
      new NullLogger(),
    );
    $result = $service->dataset('abc-123');
    $this->assertArrayNotHasKey('caveats', $result);
  }

  /**
   * Dataset(uuid) propagates getDataset errors so the controller can 404.
   */
  public function testDatasetPropagatesGetDatasetError(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')->willReturn(['error' => 'Dataset not found: abc']);
    $service = new SchemaBrowserService(
      $metastore,
      $this->createMock(DatastoreTools::class),
      $this->createMock(SearchTools::class),
      $this->createMock(DatasetCaveatRegistry::class),
      new NullLogger(),
    );
    $result = $service->dataset('abc');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('not found', $result['error']);
  }

  /**
   * Dataset(uuid) propagates listDistributions errors.
   */
  public function testDatasetPropagatesDistributionsError(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('getDataset')->willReturn(['dataset' => ['identifier' => 'abc']]);
    $metastore->method('listDistributions')->willReturn(['error' => 'Dataset not found: abc']);
    $service = new SchemaBrowserService(
      $metastore,
      $this->createMock(DatastoreTools::class),
      $this->createMock(SearchTools::class),
      $this->createMock(DatasetCaveatRegistry::class),
      new NullLogger(),
    );
    $result = $service->dataset('abc');
    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Schema(rid) passes the dictionary-enriched shape through verbatim.
   */
  public function testSchemaPassesThroughDatastoreShape(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->expects($this->once())
      ->method('getDatastoreSchema')
      ->with('rid__v1', TRUE)
      ->willReturn([
        'resource_id' => 'rid__v1',
        'columns' => [['name' => 'city', 'type' => 'text', 'dictionary_type' => 'string']],
      ]);
    $service = new SchemaBrowserService(
      $this->createMock(MetastoreTools::class),
      $datastore,
      $this->createMock(SearchTools::class),
      $this->createMock(DatasetCaveatRegistry::class),
      new NullLogger(),
    );
    $result = $service->schema('rid__v1');
    $this->assertSame('rid__v1', $result['resource_id']);
    $this->assertSame('string', $result['columns'][0]['dictionary_type']);
  }

  /**
   * Sample(rid, n) clamps the row count to the configured maximum (50).
   */
  public function testSampleClampsN(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->expects($this->exactly(2))
      ->method('sampleRows')
      ->with(
        $this->equalTo('rid__v1'),
        $this->logicalOr($this->equalTo(50), $this->equalTo(1)),
      )
      ->willReturn(['resource_id' => 'rid__v1', 'rows' => []]);
    $service = new SchemaBrowserService(
      $this->createMock(MetastoreTools::class),
      $datastore,
      $this->createMock(SearchTools::class),
      $this->createMock(DatasetCaveatRegistry::class),
      new NullLogger(),
    );
    $service->sample('rid__v1', 1000);
    $service->sample('rid__v1', 0);
  }

  /**
   * Distinct(rid, col, limit) clamps to the configured maximum (500).
   */
  public function testDistinctClampsLimit(): void {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->expects($this->exactly(2))
      ->method('distinctValues')
      ->with(
        $this->equalTo('rid__v1'),
        $this->equalTo('city'),
        $this->logicalOr($this->equalTo(500), $this->equalTo(1)),
      )
      ->willReturn(['values' => []]);
    $service = new SchemaBrowserService(
      $this->createMock(MetastoreTools::class),
      $datastore,
      $this->createMock(SearchTools::class),
      $this->createMock(DatasetCaveatRegistry::class),
      new NullLogger(),
    );
    $service->distinct('rid__v1', 'city', 9999);
    $service->distinct('rid__v1', 'city', 0);
  }

}
