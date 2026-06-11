<?php

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\dkan_ai_query\Service\ResourceIdResolver;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResourceIdResolverTest extends TestCase {

  protected function buildMetastore(array $datasets, array $distributions = []): MetastoreTools {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')->willReturnCallback(function (int $offset, int $limit) use ($datasets) {
      $page = array_slice($datasets, $offset, $limit);
      return ['datasets' => $page, 'total' => count($datasets), 'offset' => $offset, 'limit' => $limit];
    });
    $metastore->method('listDistributions')->willReturnCallback(function (string $uuid) use ($distributions) {
      return ['distributions' => $distributions[$uuid] ?? []];
    });
    return $metastore;
  }

  protected function buildDatastore(array $statusByResourceId): DatastoreTools {
    $datastore = $this->createMock(DatastoreTools::class);
    $datastore->method('getImportStatus')->willReturnCallback(function (string $rid) use ($statusByResourceId) {
      return ['status' => $statusByResourceId[$rid] ?? 'not_imported'];
    });
    return $datastore;
  }

  public function testNormalizeStripsQuotes(): void {
    $this->assertEquals('abc__123', ResourceIdResolver::normalize('"abc__123"'));
    $this->assertEquals("abc__123", ResourceIdResolver::normalize("'abc__123'"));
    $this->assertEquals('abc__123', ResourceIdResolver::normalize('  abc__123  '));
    $this->assertEquals('"', ResourceIdResolver::normalize('"'));
  }

  public function testResolveDirectId(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'D1']];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets),
      $this->buildDatastore(['abc__123' => 'done']),
    );
    $this->assertEquals('abc__123', $resolver->resolve('abc__123'));
  }

  public function testResolveByVersionSuffix(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'D1']];
    $distributions = ['d1' => [['resource_id' => 'real__123', 'identifier' => 'dist1']]];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore([
        'corrupted__123' => 'not_imported',
        'real__123' => 'done',
      ]),
    );
    $this->assertEquals('real__123', $resolver->resolve('corrupted__123'));
  }

  public function testResolveByIdentifierPrefix(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'D1']];
    $distributions = ['d1' => [['resource_id' => 'abcdef0000__999', 'identifier' => 'dist1']]];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore([
        'abcdef9999__888' => 'not_imported',
        'abcdef0000__999' => 'done',
      ]),
    );
    $this->assertEquals('abcdef0000__999', $resolver->resolve('abcdef9999__888'));
  }

  public function testResolveByDatasetTitle(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'Shark Tagging']];
    $distributions = ['d1' => [['resource_id' => 'rid__1', 'identifier' => 'dist1']]];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore(['rid__1' => 'done']),
    );
    $this->assertEquals('rid__1', $resolver->resolve('shark'));
  }

  public function testResolveReturnsNullOnMiss(): void {
    $resolver = new ResourceIdResolver(
      $this->buildMetastore([]),
      $this->buildDatastore([]),
    );
    $this->assertNull($resolver->resolve('nope__123'));
    $this->assertNull($resolver->resolve('Some unknown title'));
  }

  public function testResolveDistributionUuid(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'D1']];
    $distributions = ['d1' => [['resource_id' => 'rid__1', 'identifier' => 'dist-uuid-1']]];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore([]),
    );
    $this->assertEquals('dist-uuid-1', $resolver->resolveDistributionUuid('rid__1'));
  }

  public function testGetAllDatasetsPaginatesPastSinglePage(): void {
    // Build 250 datasets so the resolver has to fetch 3 pages of 100.
    $datasets = [];
    for ($i = 0; $i < 250; $i++) {
      $datasets[] = ['identifier' => "ds-$i", 'title' => "Dataset $i"];
    }
    $distributions = ['ds-249' => [['resource_id' => 'late__999', 'identifier' => 'dist-late']]];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore(['late__999' => 'done']),
    );
    // The resolver must page past the first 100/200 chunks to reach ds-249.
    $this->assertEquals('late__999', $resolver->resolve('Dataset 249'));
  }

  public function testFindDatasetResourcesEmptyTitle(): void {
    $resolver = new ResourceIdResolver(
      $this->buildMetastore([]),
      $this->buildDatastore([]),
    );
    $result = $resolver->findDatasetResources('  ');
    $this->assertArrayHasKey('error', $result);
  }

  public function testResolveToDatasetUuidFromUuid(): void {
    $datasets = [
      ['identifier' => 'aaaa-1111', 'title' => 'Alpha'],
      ['identifier' => 'bbbb-2222', 'title' => 'Beta'],
    ];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets),
      $this->buildDatastore([]),
    );
    $this->assertEquals('bbbb-2222', $resolver->resolveToDatasetUuid('bbbb-2222'));
  }

  public function testResolveToDatasetUuidFromResourceId(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'Crime']];
    $distributions = ['d1' => [['resource_id' => 'rid__1', 'identifier' => 'dist1']]];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore(['rid__1' => 'done']),
    );
    $this->assertEquals('d1', $resolver->resolveToDatasetUuid('rid__1'));
  }

  public function testResolveToDatasetUuidFromTitle(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'Shark Tagging']];
    $distributions = ['d1' => [['resource_id' => 'rid__1', 'identifier' => 'dist1']]];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore(['rid__1' => 'done']),
    );
    $this->assertEquals('d1', $resolver->resolveToDatasetUuid('shark'));
  }

  public function testResolveToDatasetUuidReturnsNullOnMiss(): void {
    $resolver = new ResourceIdResolver(
      $this->buildMetastore([]),
      $this->buildDatastore([]),
    );
    $this->assertNull($resolver->resolveToDatasetUuid('no-such-id'));
    $this->assertNull($resolver->resolveToDatasetUuid('nope__123'));
  }

  public function testFindDatasetResourcesReturnsMultipleMatchesOnAmbiguity(): void {
    // Two titles contain "asthma" — neither is an exact match for the
    // search term, so the resolver must surface both candidates rather
    // than silently picking the first.
    $datasets = [
      ['identifier' => 'd1', 'title' => 'Asthma Prevalence'],
      ['identifier' => 'd2', 'title' => 'Childhood Asthma Hospitalizations'],
    ];
    $distributions = [
      'd1' => [['resource_id' => 'rid-1__1', 'identifier' => 'dist1']],
      'd2' => [['resource_id' => 'rid-2__1', 'identifier' => 'dist2']],
    ];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore([]),
    );
    $result = $resolver->findDatasetResources('asthma');
    $this->assertArrayHasKey('multiple_matches', $result);
    $this->assertSame(2, $result['match_count']);
    $this->assertArrayHasKey('refine_hint', $result);
    $this->assertCount(2, $result['multiple_matches']);
    $this->assertEquals(
      [
        ['dataset_id' => 'd1', 'title' => 'Asthma Prevalence'],
        ['dataset_id' => 'd2', 'title' => 'Childhood Asthma Hospitalizations'],
      ],
      $result['multiple_matches'],
    );
    // Critically: no dataset_id / distributions keys — the agent must not
    // be able to fall through to a downstream call without disambiguating.
    $this->assertArrayNotHasKey('dataset_id', $result);
    $this->assertArrayNotHasKey('distributions', $result);
  }

  public function testFindDatasetResourcesExactMatchWinsOverPartial(): void {
    // The literal title "Asthma" should beat the substring match against
    // "Asthma Prevalence" — exact wins, so the result is unambiguous.
    $datasets = [
      ['identifier' => 'd1', 'title' => 'Asthma Prevalence'],
      ['identifier' => 'd2', 'title' => 'Asthma'],
    ];
    $distributions = [
      'd2' => [['resource_id' => 'rid-2__1', 'identifier' => 'dist2']],
    ];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore([]),
    );
    $result = $resolver->findDatasetResources('asthma');
    $this->assertArrayNotHasKey('multiple_matches', $result);
    $this->assertSame('d2', $result['dataset_id']);
    $this->assertSame('Asthma', $result['title']);
  }

  public function testFindDatasetResourcesCapsCandidateList(): void {
    // 8 datasets containing "set" but only the first 5 should appear in
    // the candidates list; match_count still reports the full total so
    // the agent knows it needs a more specific search term.
    $datasets = [];
    for ($i = 1; $i <= 8; $i++) {
      $datasets[] = ['identifier' => "d$i", 'title' => "Dataset $i"];
    }
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets),
      $this->buildDatastore([]),
    );
    $result = $resolver->findDatasetResources('dataset');
    $this->assertArrayHasKey('multiple_matches', $result);
    $this->assertCount(5, $result['multiple_matches']);
    $this->assertSame(8, $result['match_count']);
  }

  public function testFindDatasetResourcesReturnsErrorWhenNothingMatches(): void {
    $datasets = [['identifier' => 'd1', 'title' => 'Crime Data']];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets),
      $this->buildDatastore([]),
    );
    $result = $resolver->findDatasetResources('nope');
    $this->assertArrayHasKey('error', $result);
  }

  public function testResolveReturnsNullWhenTitleIsAmbiguous(): void {
    // resolve() must NOT silently pick the first match — the whole
    // point of multiple_matches is to refuse a guess.
    $datasets = [
      ['identifier' => 'd1', 'title' => 'Asthma Prevalence'],
      ['identifier' => 'd2', 'title' => 'Childhood Asthma Hospitalizations'],
    ];
    $distributions = [
      'd1' => [['resource_id' => 'rid-1__1']],
      'd2' => [['resource_id' => 'rid-2__1']],
    ];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets, $distributions),
      $this->buildDatastore(['rid-1__1' => 'done', 'rid-2__1' => 'done']),
    );
    $this->assertNull($resolver->resolve('asthma'));
  }

  public function testResolveToDatasetUuidReturnsNullWhenTitleIsAmbiguous(): void {
    $datasets = [
      ['identifier' => 'd1', 'title' => 'Asthma Prevalence'],
      ['identifier' => 'd2', 'title' => 'Childhood Asthma Hospitalizations'],
    ];
    $resolver = new ResourceIdResolver(
      $this->buildMetastore($datasets),
      $this->buildDatastore([]),
    );
    $this->assertNull($resolver->resolveToDatasetUuid('asthma'));
  }

  public function testLoggerWarningOnMaxDatasetsCap(): void {
    // Pretend the catalog is huge by reporting a total beyond the cap.
    $datasets = [];
    for ($i = 0; $i < 100; $i++) {
      $datasets[] = ['identifier' => "ds-$i", 'title' => "DS $i"];
    }
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')->willReturn([
      'datasets' => $datasets,
      // Claim there are 5000, more than MAX_DATASETS=2000.
      'total' => 5000,
    ]);
    $metastore->method('listDistributions')->willReturn(['distributions' => []]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->atLeastOnce())
      ->method('warning')
      ->with($this->stringContains('capped'));

    $resolver = new ResourceIdResolver(
      $metastore,
      $this->buildDatastore([]),
      $logger,
    );
    // Trigger getAllDatasets via a title search miss.
    $resolver->resolve('no-such-title');
  }

}
