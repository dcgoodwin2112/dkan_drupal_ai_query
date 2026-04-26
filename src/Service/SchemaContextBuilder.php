<?php

namespace Drupal\dkan_drupal_ai_query\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;

/**
 * Builds dataset / catalog schema context for the agent system prompt.
 *
 * Ported from dkan_nl_query/Service/SchemaContextBuilder.php with no logic
 * changes — only namespace and cache-key prefix.
 */
class SchemaContextBuilder {

  public function __construct(
    protected MetastoreTools $metastoreTools,
    protected DatastoreTools $datastoreTools,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Build schema context for a single dataset.
   */
  public function buildContext(string $datasetId): array {
    $cacheKey = "dkan_drupal_ai_query:context:$datasetId";
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $datasetResult = $this->metastoreTools->getDataset($datasetId);
    if (isset($datasetResult['error'])) {
      return $datasetResult;
    }
    $dataset = $datasetResult['dataset'] ?? $datasetResult;

    $distributions = $this->metastoreTools->listDistributions($datasetId);
    if (isset($distributions['error'])) {
      return $distributions;
    }

    $resources = [];
    foreach ($distributions['distributions'] ?? [] as $dist) {
      $resourceId = $dist['resource_id'] ?? NULL;
      if (!$resourceId) {
        continue;
      }

      $importStatus = $this->datastoreTools->getImportStatus($resourceId);
      if (($importStatus['status'] ?? '') !== 'done') {
        continue;
      }

      $schema = $this->datastoreTools->getDatastoreSchema($resourceId);
      if (isset($schema['error'])) {
        continue;
      }

      $stats = $this->datastoreTools->getDatastoreStats($resourceId);
      $sampleValues = $this->fetchSampleValues($resourceId, $schema['columns'] ?? []);

      $columns = [];
      foreach ($schema['columns'] ?? [] as $col) {
        $colInfo = [
          'name' => $col['name'],
          'type' => $col['type'] ?? 'text',
          'description' => $col['description'] ?? $col['name'],
        ];
        if (!isset($stats['error'])) {
          foreach ($stats['columns'] ?? [] as $statCol) {
            if ($statCol['name'] === $col['name']) {
              $colInfo['distinct_count'] = $statCol['distinct_count'] ?? NULL;
              $colInfo['null_count'] = $statCol['null_count'] ?? NULL;
              $colInfo['min'] = $statCol['min'] ?? NULL;
              $colInfo['max'] = $statCol['max'] ?? NULL;
              break;
            }
          }
        }
        if (isset($sampleValues[$col['name']])) {
          $colInfo['sample_values'] = $sampleValues[$col['name']];
        }
        $columns[] = $colInfo;
      }

      $resources[] = [
        'resource_id' => $resourceId,
        'title' => $dist['title'] ?? $dist['media_type'] ?? 'Unknown',
        'columns' => $columns,
        'total_rows' => $stats['total_rows'] ?? NULL,
      ];
    }

    $context = [
      'title' => $dataset['title'] ?? 'Unknown',
      'description' => $dataset['description'] ?? '',
      'keywords' => $dataset['keyword'] ?? [],
      'themes' => $dataset['theme'] ?? [],
      'resources' => $resources,
    ];
    $this->cache->set($cacheKey, $context, time() + 3600);
    return $context;
  }

  /**
   * Build catalog context listing all available datasets.
   */
  public function buildCatalogContext(): array {
    $cacheKey = 'dkan_drupal_ai_query:catalog';
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $result = $this->metastoreTools->listDatasets(0, 50);
    $datasets = [];
    foreach ($result['datasets'] ?? [] as $ds) {
      $full = $this->metastoreTools->getDataset($ds['identifier']);
      $meta = $full['dataset'] ?? $full;

      $dists = $this->metastoreTools->listDistributions($ds['identifier']);
      $totalRows = 0;
      $importedResources = 0;
      foreach ($dists['distributions'] ?? [] as $dist) {
        $rid = $dist['resource_id'] ?? NULL;
        if (!$rid) {
          continue;
        }
        $status = $this->datastoreTools->getImportStatus($rid);
        if (($status['status'] ?? '') === 'done') {
          $totalRows += $status['num_of_rows'] ?? 0;
          $importedResources++;
        }
      }

      $datasets[] = [
        'identifier' => $ds['identifier'],
        'title' => $ds['title'] ?? 'Untitled',
        'description' => isset($ds['description']) ? mb_substr($ds['description'], 0, 200) : '',
        'distributions' => $ds['distributions'] ?? 0,
        'imported_resources' => $importedResources,
        'total_rows' => $totalRows,
        'keywords' => $meta['keyword'] ?? [],
        'themes' => $meta['theme'] ?? [],
      ];
    }

    $context = [
      'datasets' => $datasets,
      'total' => $result['total'] ?? count($datasets),
    ];
    $this->cache->set($cacheKey, $context, time() + 3600);
    return $context;
  }

  /**
   * Fetch up to 3 distinct sample values per column.
   */
  protected function fetchSampleValues(string $resourceId, array $columns): array {
    $samples = [];
    $result = $this->datastoreTools->queryDatastore(
      resourceId: $resourceId,
      limit: 5,
    );
    if (isset($result['error']) || empty($result['results'])) {
      return $samples;
    }
    foreach ($columns as $col) {
      $name = $col['name'];
      $seen = [];
      foreach ($result['results'] as $row) {
        $val = $row[$name] ?? '';
        if ($val !== '' && !in_array($val, $seen, TRUE)) {
          $seen[] = $val;
          if (count($seen) >= 3) {
            break;
          }
        }
      }
      if ($seen) {
        $samples[$name] = $seen;
      }
    }
    return $samples;
  }

}
