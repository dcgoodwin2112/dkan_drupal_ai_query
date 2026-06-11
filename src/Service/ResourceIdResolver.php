<?php

namespace Drupal\dkan_ai_query\Service;

use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Psr\Log\LoggerInterface;

/**
 * Resolves a user-supplied resource_id, including fuzzy matching.
 *
 * Accepts an exact identifier__version, a corrupted variant the LLM may have
 * mangled (matched on version suffix or 6-char identifier prefix), or a
 * dataset title for fuzzy lookup.
 */
class ResourceIdResolver {

  /**
   * Hard cap on how many datasets to enumerate during fuzzy resolution.
   */
  private const MAX_DATASETS = 2000;

  /**
   * Cap on candidate count returned in a `multiple_matches` payload.
   *
   * Larger result sets push the LLM toward refining the search term
   * (`refine_hint`) rather than picking blindly from a long list.
   */
  private const MAX_CANDIDATES = 5;

  /**
   * Per-instance cache of every dataset summary, populated on first need.
   *
   * @var array|null
   */
  protected ?array $datasetsCache = NULL;

  /**
   * Per-instance cache: dataset_uuid => distributions array.
   *
   * @var array
   */
  protected array $distributionsCache = [];

  /**
   * Per-instance cache: resource_id => import status string.
   *
   * @var array
   */
  protected array $importStatusCache = [];

  public function __construct(
    protected MetastoreTools $metastoreTools,
    protected DatastoreTools $datastoreTools,
    protected ?LoggerInterface $logger = NULL,
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
      if ($this->getImportStatus($input) === 'done') {
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
    foreach ($this->getAllDatasets() as $ds) {
      foreach ($this->getDistributions($ds['identifier']) as $dist) {
        if (($dist['resource_id'] ?? '') === $resourceId && !empty($dist['identifier'])) {
          return $dist['identifier'];
        }
      }
    }
    return NULL;
  }

  /**
   * Map a {hash}__{version} resource id back to its parent dataset UUID.
   *
   * @return string|null
   *   Dataset UUID, or NULL when no dataset owns this resource.
   */
  public function resolveDatasetUuid(string $resourceId): ?string {
    foreach ($this->getAllDatasets() as $ds) {
      foreach ($this->getDistributions($ds['identifier']) as $dist) {
        if (($dist['resource_id'] ?? '') === $resourceId) {
          return $ds['identifier'] ?? NULL;
        }
      }
    }
    return NULL;
  }

  /**
   * Resolve any input form (UUID, resource_id, title) to a dataset UUID.
   *
   * Lets ID-taking tools accept the same fuzzy formats the datastore tools
   * already do, so the LLM never has to remember which tool wants which
   * identifier flavor. Order:
   * 1. Direct dataset-UUID match against the catalog.
   * 2. resource_id (`{hash}__{version}`) — resolved through resolve() and
   *    mapped back to its parent dataset.
   * 3. Fuzzy title substring match.
   *
   * @return string|null
   *   The dataset UUID, or NULL if no match.
   */
  public function resolveToDatasetUuid(string $input): ?string {
    foreach ($this->getAllDatasets() as $ds) {
      if (($ds['identifier'] ?? '') === $input) {
        return $input;
      }
    }
    if (str_contains($input, '__')) {
      $resolved = $this->resolve($input);
      if ($resolved !== NULL) {
        return $this->resolveDatasetUuid($resolved);
      }
    }
    $result = $this->findDatasetResources($input);
    if (!isset($result['error'])) {
      return $result['dataset_id'] ?? NULL;
    }
    return NULL;
  }

  /**
   * Find a dataset by partial title and return its distributions.
   *
   * Three response shapes:
   * - `{error: ...}` — no titles matched.
   * - `{dataset_id, title, distributions}` — exactly one match (or a single
   *    case-insensitive exact match wins over partial matches).
   * - `{multiple_matches: [...], match_count, refine_hint}` — two or more
   *    titles matched; the agent must disambiguate before resolving an id.
   *
   * Returning the first partial match silently risks answering the wrong
   * dataset's question — so when the search is genuinely ambiguous, this
   * surfaces the candidates and forces the caller to narrow.
   */
  public function findDatasetResources(string $title): array {
    $title = strtolower(trim($title));
    if ($title === '') {
      return ['error' => 'Title search term is required.'];
    }
    $exact = [];
    $partial = [];
    foreach ($this->getAllDatasets() as $ds) {
      $candidate = strtolower((string) ($ds['title'] ?? ''));
      if ($candidate === '') {
        continue;
      }
      if ($candidate === $title) {
        $exact[] = $ds;
      }
      elseif (str_contains($candidate, $title)) {
        $partial[] = $ds;
      }
    }

    // Single exact title match always wins over any partial matches.
    if (count($exact) === 1) {
      return $this->singleMatch($exact[0]);
    }
    $matches = $exact ?: $partial;
    $count = count($matches);
    if ($count === 0) {
      return ['error' => "No dataset found matching: $title"];
    }
    if ($count === 1) {
      return $this->singleMatch($matches[0]);
    }
    return [
      'multiple_matches' => array_map(
        fn(array $ds): array => [
          'dataset_id' => $ds['identifier'] ?? NULL,
          'title' => $ds['title'] ?? NULL,
        ],
        array_slice($matches, 0, self::MAX_CANDIDATES),
      ),
      'match_count' => $count,
      'refine_hint' => sprintf(
        'Multiple datasets match "%s". Ask the user which one they meant, or call find_dataset_resources again with a more specific title.',
        $title,
      ),
    ];
  }

  /**
   * Build the single-match response shape.
   */
  protected function singleMatch(array $dataset): array {
    return [
      'dataset_id' => $dataset['identifier'],
      'title' => $dataset['title'],
      'distributions' => $this->getDistributions($dataset['identifier']),
    ];
  }

  /**
   * Find an imported resource whose id ends in the given version suffix.
   */
  protected function findByVersion(string $version): ?string {
    foreach ($this->getAllDatasets() as $ds) {
      foreach ($this->getDistributions($ds['identifier']) as $dist) {
        $rid = $dist['resource_id'] ?? '';
        if ($rid && str_ends_with($rid, "__$version") && $this->getImportStatus($rid) === 'done') {
          return $rid;
        }
      }
    }
    return NULL;
  }

  /**
   * Find an imported resource whose identifier starts with the given prefix.
   */
  protected function findByPrefix(string $prefix): ?string {
    foreach ($this->getAllDatasets() as $ds) {
      foreach ($this->getDistributions($ds['identifier']) as $dist) {
        $rid = $dist['resource_id'] ?? '';
        if ($rid && str_starts_with($rid, $prefix) && $this->getImportStatus($rid) === 'done') {
          return $rid;
        }
      }
    }
    return NULL;
  }

  /**
   * Return every dataset summary in the catalog, paginated under the hood.
   *
   * Cached per request so repeated resolver methods within a single solve
   * don't refetch. Caps at MAX_DATASETS with a warning to keep pathological
   * sites from spinning the metastore.
   */
  protected function getAllDatasets(): array {
    if ($this->datasetsCache !== NULL) {
      return $this->datasetsCache;
    }
    $all = [];
    $offset = 0;
    $pageSize = 100;
    do {
      $page = $this->metastoreTools->listDatasets($offset, $pageSize);
      $items = $page['datasets'] ?? [];
      foreach ($items as $ds) {
        $all[] = $ds;
      }
      $total = (int) ($page['total'] ?? count($items));
      $offset += count($items);
      if ($offset >= self::MAX_DATASETS && $offset < $total) {
        $this->logger?->warning('Dataset enumeration capped at @n; resolver may miss some resources.', [
          '@n' => self::MAX_DATASETS,
        ]);
        break;
      }
    } while ($items && $offset < $total);
    return $this->datasetsCache = $all;
  }

  /**
   * Return distributions for a dataset, memoized per request.
   */
  protected function getDistributions(string $datasetUuid): array {
    if (!isset($this->distributionsCache[$datasetUuid])) {
      $resp = $this->metastoreTools->listDistributions($datasetUuid);
      $this->distributionsCache[$datasetUuid] = $resp['distributions'] ?? [];
    }
    return $this->distributionsCache[$datasetUuid];
  }

  /**
   * Return the import status string for a resource_id, memoized per request.
   */
  protected function getImportStatus(string $resourceId): string {
    if (!array_key_exists($resourceId, $this->importStatusCache)) {
      $status = $this->datastoreTools->getImportStatus($resourceId);
      $this->importStatusCache[$resourceId] = (string) ($status['status'] ?? '');
    }
    return $this->importStatusCache[$resourceId];
  }

}
