<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dkan_ai_query\DatasetCaveatInterface;
use Drupal\dkan_ai_query\Service\DatasetCaveatRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests DatasetCaveatRegistry lookups and payload attachment.
 *
 * @group dkan_ai_query
 */
class DatasetCaveatRegistryTest extends TestCase {

  /**
   * Build a registry whose storage returns the given caveat entities.
   */
  protected function makeRegistry(array $entities): DatasetCaveatRegistry {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($entities);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('dataset_caveat')->willReturn($storage);
    return new DatasetCaveatRegistry($etm);
  }

  /**
   * Build a stub caveat entity that returns the given UUID + caveat array.
   */
  protected function caveat(string $uuid, array $caveatArray): DatasetCaveatInterface {
    $entity = $this->createMock(DatasetCaveatInterface::class);
    $entity->method('getDatasetUuid')->willReturn($uuid);
    $entity->method('toCaveatArray')->willReturn($caveatArray);
    return $entity;
  }

  /**
   * Empty storage yields no caveats or datasets.
   */
  public function testEmptyStorageReturnsNothing(): void {
    $registry = $this->makeRegistry([]);
    $this->assertNull($registry->getCaveats('any-uuid'));
    $this->assertSame([], $registry->getColumnCaveats('any-uuid'));
    $this->assertSame([], $registry->listDatasets());
  }

  /**
   * A registered dataset returns its caveat record.
   */
  public function testReturnsCaveatsForRegisteredDataset(): void {
    $registry = $this->makeRegistry([
      'abc_123' => $this->caveat('abc-123', [
        'suppression' => 'Counts <10 suppressed.',
        'column_caveats' => ['rate' => 'Per 100k.'],
        'freshness' => ['updated' => '2024-01-01'],
      ]),
    ]);

    $caveats = $registry->getCaveats('abc-123');
    $this->assertNotNull($caveats);
    $this->assertEquals('Counts <10 suppressed.', $caveats['suppression']);
    $this->assertEquals(['rate' => 'Per 100k.'], $caveats['column_caveats']);

    $this->assertEquals(['rate' => 'Per 100k.'], $registry->getColumnCaveats('abc-123'));
    $this->assertEquals(['abc-123'], $registry->listDatasets());
  }

  /**
   * A blank record returns an empty array, not NULL.
   */
  public function testEmptyEntityYieldsEmptyArrayNotNull(): void {
    // A saved entity with all fields blank surfaces as an empty array — the
    // caller can distinguish "no record" (null) from "blank record" ([]).
    $registry = $this->makeRegistry([
      'abc_123' => $this->caveat('abc-123', []),
    ]);
    $this->assertSame([], $registry->getCaveats('abc-123'));
    $this->assertSame([], $registry->getColumnCaveats('abc-123'));
    $this->assertEquals(['abc-123'], $registry->listDatasets());
  }

  /**
   * An unknown dataset UUID returns NULL.
   */
  public function testUnknownDatasetReturnsNull(): void {
    $registry = $this->makeRegistry([
      'abc_123' => $this->caveat('abc-123', ['suppression' => 'x']),
    ]);
    $this->assertNull($registry->getCaveats('different-uuid'));
    $this->assertSame([], $registry->getColumnCaveats('different-uuid'));
  }

  /**
   * Storage failures degrade to empty results.
   */
  public function testStorageFailureIsHandledSoftly(): void {
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willThrowException(new \RuntimeException('storage unavailable'));
    $registry = new DatasetCaveatRegistry($etm);
    $this->assertSame([], $registry->listDatasets());
    $this->assertNull($registry->getCaveats('anything'));
  }

  /**
   * Entities without a dataset UUID are skipped.
   */
  public function testEntityWithoutUuidIsSkipped(): void {
    $registry = $this->makeRegistry([
      'broken' => $this->caveat('', ['suppression' => 'x']),
      'good' => $this->caveat('abc-123', ['suppression' => 'y']),
    ]);
    $this->assertEquals(['abc-123'], $registry->listDatasets());
  }

  /**
   * Multiple caveat records are tracked per dataset.
   */
  public function testMultipleDatasets(): void {
    $registry = $this->makeRegistry([
      'a_1' => $this->caveat('a-1', ['suppression' => 'A']),
      'b_2' => $this->caveat('b-2', ['column_caveats' => ['x' => 'B']]),
    ]);
    $this->assertEquals(['a-1', 'b-2'], $registry->listDatasets());
    $this->assertEquals('A', $registry->getCaveats('a-1')['suppression']);
    $this->assertEquals(['x' => 'B'], $registry->getColumnCaveats('b-2'));
  }

  /**
   * Attach() merges caveats into a payload.
   */
  public function testAttachAddsCaveatsWhenPopulated(): void {
    $registry = $this->makeRegistry([
      'abc_123' => $this->caveat('abc-123', [
        'suppression' => 'Counts <10 suppressed.',
        'column_caveats' => ['rate' => 'Per 100k.'],
      ]),
    ]);
    $payload = ['distributions' => [['resource_id' => 'rid__1']]];
    $merged = $registry->attach($payload, 'abc-123');
    $this->assertArrayHasKey('caveats', $merged);
    $this->assertEquals('Counts <10 suppressed.', $merged['caveats']['suppression']);
    $this->assertSame($payload['distributions'], $merged['distributions']);
  }

  /**
   * Attach() leaves the payload untouched without a record.
   */
  public function testAttachSkipsWhenNoRecord(): void {
    $registry = $this->makeRegistry([]);
    $payload = ['distributions' => [['resource_id' => 'rid__1']]];
    $merged = $registry->attach($payload, 'no-such-uuid');
    $this->assertArrayNotHasKey('caveats', $merged);
    $this->assertSame($payload, $merged);
  }

  /**
   * Attach() skips a blank caveat record.
   */
  public function testAttachSkipsWhenRecordExistsButBlank(): void {
    // Empty caveat record (saved entity, all fields blank). The registry
    // returns [] to distinguish from "no record" (NULL), but attach() must
    // not surface a `caveats: []` key — neither shape carries actionable
    // guidance for the agent.
    $registry = $this->makeRegistry([
      'abc_123' => $this->caveat('abc-123', []),
    ]);
    $payload = ['distributions' => []];
    $merged = $registry->attach($payload, 'abc-123');
    $this->assertArrayNotHasKey('caveats', $merged);
  }

  /**
   * Attach() overwrites a pre-existing caveats key.
   */
  public function testAttachOverwritesExistingCaveatsKey(): void {
    // Defensive: if a caller has already populated `caveats`, the registry's
    // authoritative record wins.
    $registry = $this->makeRegistry([
      'abc_123' => $this->caveat('abc-123', ['suppression' => 'fresh']),
    ]);
    $payload = ['caveats' => ['stale' => 'value']];
    $merged = $registry->attach($payload, 'abc-123');
    $this->assertSame(['suppression' => 'fresh'], $merged['caveats']);
  }

  /**
   * ResetCache() forces a storage reload.
   */
  public function testResetCacheForcesReload(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))
      ->method('loadMultiple')
      ->willReturnOnConsecutiveCalls(
        ['a_1' => $this->caveat('a-1', ['suppression' => 'first'])],
        ['a_1' => $this->caveat('a-1', ['suppression' => 'second'])],
      );
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);
    $registry = new DatasetCaveatRegistry($etm);

    $this->assertEquals('first', $registry->getCaveats('a-1')['suppression']);
    $registry->resetCache();
    $this->assertEquals('second', $registry->getCaveats('a-1')['suppression']);
  }

}
