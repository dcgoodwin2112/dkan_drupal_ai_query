<?php

namespace Drupal\dkan_ai_query\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Stores per-thread artifacts (table data, chart specs) for the poll endpoint.
 *
 * Distinct from ai_agents' status timeline — that one is a chronological
 * event stream. Artifacts are payloads the UI needs to render (data tables,
 * Vega-Lite specs) and are appended in order.
 */
class ArtifactStorage {

  protected const STORE_NAME = 'dkan_ai_query_artifacts';

  public function __construct(
    protected PrivateTempStoreFactory $tempStore,
  ) {}

  /**
   * Append an artifact for a given thread.
   *
   * Silently no-ops when there is no session (CLI contexts like Drush eval
   * or future cron runs). The web UI is the only consumer of artifacts.
   *
   * @param string $threadId
   *   The agent thread id.
   * @param array $artifact
   *   Artifact array. Must include a 'type' key (e.g. 'data', 'chart').
   */
  public function append(string $threadId, array $artifact): void {
    try {
      $store = $this->tempStore->get(self::STORE_NAME);
      $key = $this->key($threadId);
      $existing = $store->get($key);
      $items = $existing ? Json::decode($existing) : [];
      $artifact['time'] = $artifact['time'] ?? microtime(TRUE);
      $items[] = $artifact;
      $store->set($key, Json::encode($items));
    }
    catch (\Throwable $e) {
      // No session (CLI). Artifacts are UI-only; safe to drop.
    }
  }

  /**
   * Read all artifacts for a thread.
   */
  public function load(string $threadId): array {
    try {
      $store = $this->tempStore->get(self::STORE_NAME);
      $raw = $store->get($this->key($threadId));
      return $raw ? (array) Json::decode($raw) : [];
    }
    catch (\Throwable $e) {
      return [];
    }
  }

  /**
   * Delete all artifacts for a thread.
   */
  public function delete(string $threadId): void {
    try {
      $store = $this->tempStore->get(self::STORE_NAME);
      $store->delete($this->key($threadId));
    }
    catch (\Throwable $e) {
      // No session (CLI). Nothing to delete.
    }
  }

  /**
   * Build the temp store key for a thread id.
   */
  protected function key(string $threadId): string {
    return 'thread_' . $threadId;
  }

}
