<?php

namespace Drupal\dkan_drupal_ai_query\Service;

use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;

/**
 * Resolves a user-supplied resource_id, including fuzzy matching.
 *
 * Extracted from dkan_nl_query\Service\ToolExecutor::resolveResourceId(),
 * findResourceByVersion(), findResourceByPrefix(), findDatasetResources().
 * Logic unchanged.
 */
class ResourceIdResolver {

  public function __construct(
    protected MetastoreTools $metastoreTools,
    protected DatastoreTools $datastoreTools,
  ) {}

  /**
   * Strip stray surrounding quotes the LLM sometimes wraps around ids.
   *
   * Tool-call arguments are already JSON-decoded by ai_agents before they
   * reach getContextValue(); when the LLM emits literal quotes inside the
   * value (a habit picked up from quote-wrapped examples in tool
   * descriptions), they survive as part of the string. Trim them once so
   * downstream code sees the bare id.
   */
  public static function normalize(string $value): string {
    $value = trim($value);
    if (strlen($value) < 2) {
      return $value;
    }
    $first = $value[0];
    $last = substr($value, -1);
    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
      return trim(substr($value, 1, -1));
    }
    return $value;
  }

  /**
   * Resolve a resource_id from either a direct ID or dataset title.
   *
   * Accepts identifier__version format directly, or a dataset title which is
   * resolved to the first imported resource_id. If a direct resource_id fails
   * validation, falls back to fuzzy matching against all known resources
   * (handles LLM hex-string corruption).
   *
   * @return string|null
   *   The resolved resource_id, or NULL if no match.
   */
  public function resolve(string $input): ?string {
    if (str_contains($input, '__')) {
      $status = $this->datastoreTools->getImportStatus($input);
      if (($status['status'] ?? '') === 'done') {
        return $input;
      }

      $version = explode('__', $input)[1] ?? '';
      if ($version) {
        $match = $this->findByVersion($version);
        if ($match) {
          return $match;
        }
      }

      $prefix = substr(explode('__', $input)[0], 0, 6);
      $match = $this->findByPrefix($prefix);
      if ($match) {
        return $match;
      }
    }

    $result = $this->findDatasetResources($input);
    if (!isset($result['error'])) {
      foreach ($result['distributions'] ?? [] as $dist) {
        if (!empty($dist['resource_id'])) {
          return $dist['resource_id'];
        }
      }
    }

    return NULL;
  }

  /**
   * Find the distribution UUID for a given canonical resource_id.
   *
   * The public DKAN datastore query endpoint takes a distribution UUID in
   * the URL path (`/api/1/datastore/query/{distribution_uuid}`); resource
   * ids (`{hash}__{version}`) are the internal datastore table-name form.
   * Use this when rendering the equivalent API call to a user.
   *
   * @return string|null
   *   Distribution UUID, or NULL if no matching distribution exists.
   */
  public function resolveDistributionUuid(string $resourceId): ?string {
    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      $dists = $this->metastoreTools->listDistributions($ds['identifier']);
      foreach ($dists['distributions'] ?? [] as $dist) {
        if (($dist['resource_id'] ?? '') === $resourceId && !empty($dist['identifier'])) {
          return $dist['identifier'];
        }
      }
    }
    return NULL;
  }

  /**
   * Find a dataset by partial title and return its distributions.
   */
  public function findDatasetResources(string $title): array {
    $title = strtolower(trim($title));
    if ($title === '') {
      return ['error' => 'Title search term is required.'];
    }
    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      if (str_contains(strtolower($ds['title'] ?? ''), $title)) {
        $dists = $this->metastoreTools->listDistributions($ds['identifier']);
        return [
          'dataset_id' => $ds['identifier'],
          'title' => $ds['title'],
          'distributions' => $dists['distributions'] ?? [],
        ];
      }
    }
    return ['error' => "No dataset found matching: $title"];
  }

  /**
   * Find an imported resource whose id ends in the given version suffix.
   */
  protected function findByVersion(string $version): ?string {
    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      $dists = $this->metastoreTools->listDistributions($ds['identifier']);
      foreach ($dists['distributions'] ?? [] as $dist) {
        $rid = $dist['resource_id'] ?? '';
        if ($rid && str_ends_with($rid, "__$version")) {
          $status = $this->datastoreTools->getImportStatus($rid);
          if (($status['status'] ?? '') === 'done') {
            return $rid;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Find an imported resource whose identifier starts with the given prefix.
   */
  protected function findByPrefix(string $prefix): ?string {
    $datasets = $this->metastoreTools->listDatasets(0, 50);
    foreach ($datasets['datasets'] ?? [] as $ds) {
      $dists = $this->metastoreTools->listDistributions($ds['identifier']);
      foreach ($dists['distributions'] ?? [] as $dist) {
        $rid = $dist['resource_id'] ?? '';
        if ($rid && str_starts_with($rid, $prefix)) {
          $status = $this->datastoreTools->getImportStatus($rid);
          if (($status['status'] ?? '') === 'done') {
            return $rid;
          }
        }
      }
    }
    return NULL;
  }

}
