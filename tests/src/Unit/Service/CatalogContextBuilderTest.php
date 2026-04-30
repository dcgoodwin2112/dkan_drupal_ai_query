<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\dkan_drupal_ai_query\Service\CatalogContextBuilder;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use PHPUnit\Framework\TestCase;

class CatalogContextBuilderTest extends TestCase {

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

  protected function buildMetastore(array $datasets, ?int $totalOverride = NULL): MetastoreTools {
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
    return $metastore;
  }

  public function testReturnsEmptyStringForEmptyCatalog(): void {
    $builder = new CatalogContextBuilder($this->buildMetastore([]), $this->buildCache());
    $this->assertSame('', $builder->build());
  }

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

  public function testReturnsEmptyOnMetastoreFailure(): void {
    $metastore = $this->createMock(MetastoreTools::class);
    $metastore->method('listDatasets')->willThrowException(new \RuntimeException('boom'));
    $builder = new CatalogContextBuilder($metastore, $this->buildCache());
    $this->assertSame('', $builder->build());
  }

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

}
