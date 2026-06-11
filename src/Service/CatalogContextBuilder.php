<?php

namespace Drupal\dkan_ai_query\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\dkan_query_tools\Tool\MetastoreTools;

/**
 * Build a compact catalog block (title + UUID per dataset) for the agent.
 *
 * The agent otherwise has to spend a list_datasets / find_dataset_resources
 * call just to learn the title behind a UUID it was scoped to, or to know
 * what's available before searching. Pre-seeding the catalog into the task
 * text turns those round trips into free knowledge.
 *
 * Cached for an hour at the catalog level since the metastore changes
 * infrequently relative to query traffic.
 */
class CatalogContextBuilder {

  /**
   * Cap to keep the prompt addition bounded on large catalogs.
   *
   * Sites with > MAX_LIST datasets get a truncated list plus a hint that
   * search_datasets is the right tool for finding others.
   */
  protected const MAX_LIST = 50;

  /**
   * Cap on per-build resource_id inlining lookups.
   *
   * Each single-distribution dataset costs one extra listDistributions() call
   * on cache miss. Capping protects the build from pathological catalogs
   * (e.g. 50 single-dist datasets = 50 extra calls every hour). Datasets
   * past the cap still appear in the listing without their resource_id.
   */
  protected const MAX_RESOURCE_INLINE = 25;

  protected const CID = 'dkan_ai_query:catalog_context';
  protected const TTL_SECONDS = 3600;

  public function __construct(
    protected MetastoreTools $metastore,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Return the catalog block, or an empty string if no datasets are visible.
   */
  public function build(): string {
    $cached = $this->cache->get(self::CID);
    if ($cached !== FALSE) {
      return (string) $cached->data;
    }
    try {
      $result = $this->metastore->listDatasets(0, self::MAX_LIST + 1);
    }
    catch (\Throwable) {
      return '';
    }
    $datasets = $result['datasets'] ?? [];
    if (!$datasets) {
      return '';
    }
    $total = (int) ($result['total'] ?? count($datasets));
    $shown = array_slice($datasets, 0, self::MAX_LIST);

    $lines = ['Available datasets in this catalog (use these titles in prose, the UUID for dataset-scoped tools, the resource_id (when shown) for datastore tools):'];
    $inlined = 0;
    foreach ($shown as $d) {
      $title = $d['title'] ?? '(untitled)';
      $uuid = $d['identifier'] ?? '';
      if ($uuid === '') {
        continue;
      }
      $distCount = (int) ($d['distributions'] ?? 0);
      $suffix = '';
      if ($distCount === 1 && $inlined < self::MAX_RESOURCE_INLINE) {
        $resourceId = $this->lookupSingleResourceId($uuid);
        if ($resourceId !== NULL) {
          $suffix = ' (data: ' . $resourceId . ')';
          $inlined++;
        }
      }
      elseif ($distCount > 1) {
        $suffix = ' (' . $distCount . ' data files)';
      }
      $lines[] = '- "' . $title . '" — ' . $uuid . $suffix;
    }
    if ($total > self::MAX_LIST) {
      $lines[] = '- (' . ($total - self::MAX_LIST) . ' more not shown — use search_datasets to find them)';
    }
    $out = implode("\n", $lines);

    $this->cache->set(self::CID, $out, time() + self::TTL_SECONDS);
    return $out;
  }

  /**
   * Drop the cached catalog so the next request rebuilds it.
   */
  public function invalidate(): void {
    $this->cache->delete(self::CID);
  }

  /**
   * Fetch the resource_id of a dataset's lone distribution, or NULL on miss.
   *
   * Failures (no distributions array, no resource_id derivable, error response,
   * exception) all collapse to NULL so the catalog line falls back to the
   * plain `title — uuid` shape.
   */
  protected function lookupSingleResourceId(string $datasetUuid): ?string {
    try {
      $result = $this->metastore->listDistributions($datasetUuid);
    }
    catch (\Throwable) {
      return NULL;
    }
    $distributions = $result['distributions'] ?? [];
    if (!$distributions) {
      return NULL;
    }
    $resourceId = $distributions[0]['resource_id'] ?? NULL;
    return is_string($resourceId) && $resourceId !== '' ? $resourceId : NULL;
  }

}
