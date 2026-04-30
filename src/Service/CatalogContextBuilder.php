<?php

namespace Drupal\dkan_drupal_ai_query\Service;

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

  protected const CID = 'dkan_drupal_ai_query:catalog_context';
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

    $lines = ['Available datasets in this catalog (use these titles in prose, these UUIDs in tool calls):'];
    foreach ($shown as $d) {
      $title = $d['title'] ?? '(untitled)';
      $uuid = $d['identifier'] ?? '';
      if ($uuid === '') {
        continue;
      }
      $lines[] = '- "' . $title . '" — ' . $uuid;
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

}
