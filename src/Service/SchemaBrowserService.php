<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Service;

use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_query_tools\Tool\SearchTools;
use Psr\Log\LoggerInterface;

/**
 * Composes the JSON payloads served by the catalog browse endpoints.
 *
 * Wraps the existing dkan_query_tools services so the controller stays a thin
 * HTTP shim: validation here, response shape here, HTTP status codes there.
 * Mirrors the pattern of CatalogContextBuilder and SuggestionGenerator.
 */
class SchemaBrowserService {

  /**
   * Maximum sample-row count exposed via the browse endpoint.
   */
  protected const SAMPLE_MAX = 50;

  /**
   * Maximum distinct-value count exposed via the browse endpoint.
   */
  protected const DISTINCT_MAX = 500;

  public function __construct(
    protected MetastoreTools $metastore,
    protected DatastoreTools $datastore,
    protected SearchTools $search,
    protected DatasetCaveatRegistry $caveats,
    protected LoggerInterface $logger,
  ) {}

  /**
   * List datasets, optionally filtered by a keyword.
   *
   * @param int $offset
   *   Zero-based offset (clamped to >= 0).
   * @param int $limit
   *   Page size (clamped to [1, 100]).
   * @param string $q
   *   Optional keyword. When empty, lists via the metastore; when set, routes
   *   through the search API and clamps page size to 50 (search API limit).
   *
   * @return array
   *   Normalized shape: { datasets, total, offset, limit, q }, or { error }.
   */
  public function listDatasets(int $offset, int $limit, string $q = ''): array {
    $offset = max(0, $offset);
    $limit = max(1, min(100, $limit));
    $q = trim($q);

    if ($q === '') {
      $result = $this->metastore->listDatasets($offset, $limit);
      $items = $result['datasets'] ?? [];
      return [
        'datasets' => $this->annotateCaveats($items),
        'total' => (int) ($result['total'] ?? count($items)),
        'offset' => $offset,
        'limit' => $limit,
        'q' => '',
      ];
    }

    // Search API is page-based; clamp limit to its 50-item ceiling and align
    // offset to the page boundary for honest pagination semantics.
    $pageSize = min($limit, 50);
    $page = (int) floor($offset / max($pageSize, 1)) + 1;
    $result = $this->search->searchDatasets($q, $page, $pageSize);
    if (isset($result['error'])) {
      return ['error' => (string) $result['error']];
    }
    $items = $result['results'] ?? [];
    return [
      'datasets' => $this->annotateCaveats($items),
      'total' => (int) ($result['total'] ?? count($items)),
      'offset' => ($page - 1) * $pageSize,
      'limit' => $pageSize,
      'q' => $q,
    ];
  }

  /**
   * Get a dataset's title, distributions, and caveats by UUID.
   *
   * @return array
   *   { identifier, title, description, theme?, keyword?, modified?,
   *     caveats?, distributions: [...] }, or { error }.
   */
  public function dataset(string $uuid): array {
    $datasetResult = $this->metastore->getDataset($uuid);
    if (isset($datasetResult['error'])) {
      return ['error' => (string) $datasetResult['error']];
    }
    $dataset = $datasetResult['dataset'] ?? [];

    $distResult = $this->metastore->listDistributions($uuid);
    if (isset($distResult['error'])) {
      return ['error' => (string) $distResult['error']];
    }
    $distributions = [];
    foreach ($distResult['distributions'] ?? [] as $dist) {
      $dist['has_dictionary'] = !empty($dist['describedBy']);
      $distributions[] = $dist;
    }

    $payload = [
      'identifier' => $dataset['identifier'] ?? $uuid,
      'title' => $dataset['title'] ?? NULL,
      'description' => $dataset['description'] ?? NULL,
      'theme' => $dataset['theme'] ?? NULL,
      'keyword' => $dataset['keyword'] ?? NULL,
      'modified' => $dataset['modified'] ?? NULL,
      'distributions' => $distributions,
    ];
    return $this->caveats->attach($payload, $uuid);
  }

  /**
   * Get a resource's schema (column names + dictionary-enriched types).
   */
  public function schema(string $resourceId): array {
    return $this->datastore->getDatastoreSchema($resourceId, TRUE);
  }

  /**
   * Get null/distinct/min/max stats per column.
   *
   * Lazy-loaded by the UI: large tables can take seconds.
   */
  public function stats(string $resourceId): array {
    return $this->datastore->getDatastoreStats($resourceId);
  }

  /**
   * First N rows from a resource (clamped to [1, 50]).
   */
  public function sample(string $resourceId, int $n = 5): array {
    $n = max(1, min(self::SAMPLE_MAX, $n));
    return $this->datastore->sampleRows($resourceId, $n);
  }

  /**
   * Distinct values for a column (clamped to [1, 500] with a truncated flag).
   */
  public function distinct(string $resourceId, string $column, int $limit = 50): array {
    $limit = max(1, min(self::DISTINCT_MAX, $limit));
    return $this->datastore->distinctValues($resourceId, $column, $limit);
  }

  /**
   * Tag each dataset summary with `has_caveats` if a caveat record exists.
   */
  protected function annotateCaveats(array $datasets): array {
    $withCaveats = array_flip($this->caveats->listDatasets());
    $out = [];
    foreach ($datasets as $row) {
      $uuid = $row['identifier'] ?? NULL;
      $row['has_caveats'] = $uuid !== NULL && isset($withCaveats[$uuid]);
      $out[] = $row;
    }
    return $out;
  }

}
