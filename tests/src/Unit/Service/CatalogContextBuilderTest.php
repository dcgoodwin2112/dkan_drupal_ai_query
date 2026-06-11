<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\dkan_ai_query\Service\CatalogContextBuilder;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use PHPUnit\Framework\TestCase;

/**
 * Tests CatalogContextBuilder catalog snippet generation.
 *
 * @group dkan_ai_query
 */
class CatalogContextBuilderTest extends TestCase {

  /**
   * Builds an in-memory CacheBackendInterface mock.
   */
  protected function buildCache(): CacheBackendInterface {
    $cache = $this->createMock(CacheBackendInterface::class);
    $store = [];
    $cache->method('get')->willReturnCallback(function (string $cid) use (&$store) {
      if (!isset($store[$cid])) {
        return FALSE;
      }
      $obj = new \stdClass();
      $obj->data = $store[$cid];
      return $obj;
    });
    $cache->method('set')->willReturnCallback(function (string $cid, $data) use (&$store): void {
      $store[$cid] = $data;
    });
    $cache->method('delete')->willReturnCallback(function (string $cid) use (&$store): void {
      unset($store[$cid]);
    });
    return $cache;
  }

  /**
   * Builds a MetastoreTools mock over datasets and distributions.
   */
  protected function buildMetastore(array $datasets, ?int $totalOverride = NULL, array $distributionsByUuid = []): MetastoreTools {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')->willReturnCallback(function (int $offset, int $limit) use ($datasets, $totalOverride) {
      $page = array_slice($datasets, $offset, $limit);
      return [
        'datasets' => $page,
        'total' => $totalOverride ?? count($datasets),
        'offset' => $offset,
        'limit' => $limit,
      ];
    });
    $metastore->method('listDistributions')->willReturnCallback(function (string $uuid) use ($distributionsByUuid) {
      if (!array_key_exists($uuid, $distributionsByUuid)) {
        return ['distributions' => []];
      }
      $val = $distributionsByUuid[$uuid];
      if ($val instanceof \Throwable) {
        throw $val;
      }
      return ['distributions' => $val];
    });
    return $metastore;
  }

  /**
   * An empty catalog builds an empty string.
   */
  public function testReturnsEmptyStringForEmptyCatalog(): void {
    $builder = new CatalogContextBuilder($this->buildMetastore([]), $this->buildCache());
    $this->assertSame('', $builder->build());
  }

  /**
   * Each dataset renders as a title/UUID line.
   */
  public function testFormatsTitleAndUuidPerLine(): void {
    $builder = new CatalogContextBuilder(
      $this->buildMetastore([
        ['identifier' => 'uuid-1', 'title' => 'Crime Data'],
        ['identifier' => 'uuid-2', 'title' => 'Asthma Prevalence'],
      ]),
      $this->buildCache(),
    );
    $out = $builder->build();
    $this->assertStringContainsString('Available datasets', $out);
    $this->assertStringContainsString('"Crime Data" — uuid-1', $out);
    $this->assertStringContainsString('"Asthma Prevalence" — uuid-2', $out);
  }

  /**
   * Catalogs above the cap truncate with a search hint.
   */
  public function testTruncatesAboveMaxAndAddsHint(): void {
    $datasets = [];
    for ($i = 1; $i <= 60; $i++) {
      $datasets[] = ['identifier' => 'uuid-' . $i, 'title' => 'Title ' . $i];
    }
    $builder = new CatalogContextBuilder($this->buildMetastore($datasets), $this->buildCache());
    $out = $builder->build();
    $this->assertStringContainsString('Title 1', $out);
    $this->assertStringContainsString('Title 50', $out);
    $this->assertStringNotContainsString('Title 51', $out);
    $this->assertStringContainsString('10 more not shown', $out);
    $this->assertStringContainsString('search_datasets', $out);
  }

  /**
   * Build() hits the cache on subsequent calls.
   */
  public function testCachesAcrossCalls(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $callCount = 0;
    $metastore->method('listDatasets')->willReturnCallback(function () use (&$callCount) {
      $callCount++;
      return ['datasets' => [['identifier' => 'u', 'title' => 'T']], 'total' => 1];
    });
    $builder = new CatalogContextBuilder($metastore, $this->buildCache());
    $first = $builder->build();
    $second = $builder->build();
    $this->assertSame($first, $second);
    $this->assertSame(1, $callCount, 'Second build() must hit the cache, not the metastore');
  }

  /**
   * Invalidate() forces a rebuild from the metastore.
   */
  public function testInvalidateForcesRebuild(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $callCount = 0;
    $metastore->method('listDatasets')->willReturnCallback(function () use (&$callCount) {
      $callCount++;
      return ['datasets' => [['identifier' => 'u', 'title' => 'T']], 'total' => 1];
    });
    $builder = new CatalogContextBuilder($metastore, $this->buildCache());
    $builder->build();
    $builder->invalidate();
    $builder->build();
    $this->assertSame(2, $callCount);
  }

  /**
   * Metastore failures degrade to an empty string.
   */
  public function testReturnsEmptyOnMetastoreFailure(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')->willThrowException(new \RuntimeException('boom'));
    $builder = new CatalogContextBuilder($metastore, $this->buildCache());
    $this->assertSame('', $builder->build());
  }

  /**
   * Datasets without a UUID are skipped.
   */
  public function testSkipsEntriesWithoutUuid(): void {
    $builder = new CatalogContextBuilder(
      $this->buildMetastore([
        ['identifier' => '', 'title' => 'No UUID'],
        ['identifier' => 'uuid-1', 'title' => 'Has UUID'],
      ]),
      $this->buildCache(),
    );
    $out = $builder->build();
    $this->assertStringNotContainsString('No UUID', $out);
    $this->assertStringContainsString('Has UUID', $out);
  }

  /**
   * Single-distribution datasets get their resource id inlined.
   */
  public function testInlinesResourceIdForSingleDistributionDatasets(): void {
    $builder = new CatalogContextBuilder(
      $this->buildMetastore(
        [
          ['identifier' => 'uuid-1', 'title' => 'Crime Data', 'distributions' => 1],
        ],
        NULL,
        [
          'uuid-1' => [
            ['resource_id' => 'rid-abc__1', 'identifier' => 'dist-uuid-1'],
          ],
        ],
      ),
      $this->buildCache(),
    );
    $out = $builder->build();
    $this->assertStringContainsString('"Crime Data" — uuid-1 (data: rid-abc__1)', $out);
  }

  /**
   * Multi-distribution datasets show a file count instead.
   */
  public function testShowsDistributionCountForMultiDistDatasets(): void {
    $builder = new CatalogContextBuilder(
      $this->buildMetastore([
        ['identifier' => 'uuid-2', 'title' => 'Multi Set', 'distributions' => 3],
      ]),
      $this->buildCache(),
    );
    $out = $builder->build();
    $this->assertStringContainsString('"Multi Set" — uuid-2 (3 data files)', $out);
    $this->assertStringNotContainsString('data: ', $out);
  }

  /**
   * A missing resource id falls back to a plain line.
   */
  public function testFallsBackToPlainLineWhenLookupReturnsNoResourceId(): void {
    $builder = new CatalogContextBuilder(
      $this->buildMetastore(
        [
          ['identifier' => 'uuid-1', 'title' => 'Empty Dist', 'distributions' => 1],
        ],
        NULL,
        // listDistributions returns an entry but resource_id is null.
        ['uuid-1' => [['resource_id' => NULL]]],
      ),
      $this->buildCache(),
    );
    $out = $builder->build();
    $this->assertStringContainsString('"Empty Dist" — uuid-1', $out);
    $this->assertStringNotContainsString('(data:', $out);
  }

  /**
   * A distribution lookup failure falls back to a plain line.
   */
  public function testFallsBackToPlainLineWhenListDistributionsThrows(): void {
    $builder = new CatalogContextBuilder(
      $this->buildMetastore(
        [
          ['identifier' => 'uuid-1', 'title' => 'Boom', 'distributions' => 1],
        ],
        NULL,
        ['uuid-1' => new \RuntimeException('storage down')],
      ),
      $this->buildCache(),
    );
    $out = $builder->build();
    $this->assertStringContainsString('"Boom" — uuid-1', $out);
    $this->assertStringNotContainsString('(data:', $out);
  }

  /**
   * Resource id inlining is capped at MAX_RESOURCE_INLINE.
   */
  public function testCapsResourceIdInliningAtMaxResourceInline(): void {
    // 30 single-dist datasets — only the first 25 should get a resource_id
    // inlined; the rest appear as plain lines.
    $datasets = [];
    $dists = [];
    for ($i = 1; $i <= 30; $i++) {
      $datasets[] = ['identifier' => 'uuid-' . $i, 'title' => 'Title ' . $i, 'distributions' => 1];
      $dists['uuid-' . $i] = [['resource_id' => 'rid-' . $i . '__1']];
    }
    $builder = new CatalogContextBuilder(
      $this->buildMetastore($datasets, NULL, $dists),
      $this->buildCache(),
    );
    $out = $builder->build();
    // First 25 inlined.
    $this->assertStringContainsString('"Title 1" — uuid-1 (data: rid-1__1)', $out);
    $this->assertStringContainsString('"Title 25" — uuid-25 (data: rid-25__1)', $out);
    // 26–30 still listed without the inline.
    $this->assertStringContainsString('"Title 26" — uuid-26', $out);
    $this->assertStringNotContainsString('(data: rid-26', $out);
    $this->assertStringNotContainsString('(data: rid-30', $out);
  }

  /**
   * Zero-distribution datasets get no suffix.
   */
  public function testZeroDistributionDatasetGetsNoSuffix(): void {
    $builder = new CatalogContextBuilder(
      $this->buildMetastore([
        ['identifier' => 'uuid-1', 'title' => 'Bare Dataset', 'distributions' => 0],
      ]),
      $this->buildCache(),
    );
    $out = $builder->build();
    $this->assertStringContainsString('"Bare Dataset" — uuid-1', $out);
    $this->assertStringNotContainsString('(data:', $out);
    $this->assertStringNotContainsString('data files', $out);
  }

}
