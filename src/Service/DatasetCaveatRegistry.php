<?php

namespace Drupal\dkan_drupal_ai_query\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dkan_drupal_ai_query\DatasetCaveatInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Read-only registry of curator-authored per-dataset caveats.
 *
 * Backed by `dataset_caveat` config entities (one per DKAN dataset). The
 * service exposes the same array-shaped API the YAML-backed prototype did,
 * so FunctionCall plugins don't need to change when entities are the
 * source. Caveats cover the things the LLM cannot infer from schema alone:
 * suppression rules, column gotchas, freshness/coverage windows, code
 * lists.
 */
class DatasetCaveatRegistry {

  /**
   * Lazily-loaded cache: dataset_uuid => caveat array.
   *
   * @var array<string, array>|null
   */
  protected ?array $cache = NULL;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    ?LoggerInterface $logger = NULL,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  /**
   * Return the full caveat block for a dataset, or NULL when none exists.
   *
   * Empty caveat records (a saved entity with all fields empty) return an
   * empty array `[]` so callers can distinguish "no record" from "record
   * exists but author left it blank".
   */
  public function getCaveats(string $datasetUuid): ?array {
    return $this->load()[$datasetUuid] ?? NULL;
  }

  /**
   * Return only the column_caveats map for a dataset.
   *
   * @return array<string, string>
   *   Map of column name to caveat text. Empty array when none.
   */
  public function getColumnCaveats(string $datasetUuid): array {
    $caveats = $this->getCaveats($datasetUuid);
    return $caveats['column_caveats'] ?? [];
  }

  /**
   * Merge a dataset's caveats into a tool response payload.
   *
   * Lets ID-taking tools (find_dataset_resources, list_distributions, …)
   * surface the same compliance / coverage warnings without each one
   * reimplementing the lookup-and-merge predicate. Empty caveat records
   * (a saved entity with all fields blank) are intentionally NOT
   * attached — the agent gains nothing from a `caveats: []` key, and we
   * want to preserve the registry's "no record" / "blank record"
   * distinction at the read API, not leak it into every tool's output.
   *
   * @param array $payload
   *   The tool's response array.
   * @param string $datasetUuid
   *   The dataset whose caveats to attach.
   *
   * @return array
   *   The payload, with a `caveats` key added if a populated record exists.
   */
  public function attach(array $payload, string $datasetUuid): array {
    $caveats = $this->getCaveats($datasetUuid);
    if ($caveats) {
      $payload['caveats'] = $caveats;
    }
    return $payload;
  }

  /**
   * Return the list of dataset UUIDs that have caveat records.
   *
   * @return string[]
   *   Dataset UUIDs.
   */
  public function listDatasets(): array {
    return array_keys($this->load());
  }

  /**
   * Force the cache to be rebuilt on next read (used by tests/admin actions).
   */
  public function resetCache(): void {
    $this->cache = NULL;
  }

  /**
   * Load all caveat entities and project them into the public array shape.
   */
  protected function load(): array {
    if ($this->cache !== NULL) {
      return $this->cache;
    }
    try {
      $storage = $this->entityTypeManager->getStorage('dataset_caveat');
    }
    catch (\Throwable $e) {
      $this->logger->error('DatasetCaveatRegistry: dataset_caveat storage unavailable: @msg', ['@msg' => $e->getMessage()]);
      $this->cache = [];
      return $this->cache;
    }
    $out = [];
    foreach ($storage->loadMultiple() as $entity) {
      if (!$entity instanceof DatasetCaveatInterface) {
        continue;
      }
      $uuid = $entity->getDatasetUuid();
      if ($uuid === '') {
        continue;
      }
      $out[$uuid] = $entity->toCaveatArray();
    }
    $this->cache = $out;
    return $this->cache;
  }

}
