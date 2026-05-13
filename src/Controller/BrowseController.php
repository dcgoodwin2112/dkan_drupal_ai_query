<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Controller;

use Drupal\dkan_drupal_ai_query\Service\SchemaBrowserService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read-only catalog browse endpoints for the schema-browser tab.
 *
 * Each action delegates to SchemaBrowserService and wraps the result in a
 * JsonResponse with appropriate Cache-Control headers. Cache-Control is
 * `private` because DKAN's metastore can scope datasets per-user.
 */
class BrowseController {

  /**
   * Cache-Control max-age for catalog list / dataset detail (seconds).
   */
  protected const CATALOG_TTL = 60;

  /**
   * Cache-Control max-age for schema and stats (seconds).
   *
   * Schemas are immutable per (identifier, version); stats are expensive
   * enough that a 5-minute cache is well worth the staleness window.
   */
  protected const SCHEMA_TTL = 300;

  public function __construct(
    protected SchemaBrowserService $service,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Lists datasets, optionally filtered by `?q=`.
   */
  public function datasets(Request $request): JsonResponse {
    $offset = (int) $request->query->get('offset', 0);
    $limit = (int) $request->query->get('limit', 25);
    $q = (string) $request->query->get('q', '');
    $payload = $this->service->listDatasets($offset, $limit, $q);
    if (isset($payload['error'])) {
      return $this->json($payload, 500);
    }
    return $this->json($payload, 200, self::CATALOG_TTL);
  }

  /**
   * Returns a dataset's metadata, distributions, and caveats by UUID.
   */
  public function dataset(string $uuid): JsonResponse {
    $payload = $this->service->dataset($uuid);
    if (isset($payload['error'])) {
      return $this->json($payload, 404);
    }
    return $this->json($payload, 200, self::CATALOG_TTL);
  }

  /**
   * Returns the resource's schema with dictionary-enriched types.
   */
  public function schema(string $rid): JsonResponse {
    $payload = $this->service->schema($rid);
    if (isset($payload['error'])) {
      return $this->json($payload, 404);
    }
    return $this->json($payload, 200, self::SCHEMA_TTL);
  }

  /**
   * Returns null/distinct/min/max stats per column.
   */
  public function stats(string $rid): JsonResponse {
    $payload = $this->service->stats($rid);
    if (isset($payload['error'])) {
      return $this->json($payload, 404);
    }
    return $this->json($payload, 200, self::SCHEMA_TTL);
  }

  /**
   * Returns the first N rows from the resource (clamped to 50).
   */
  public function sample(string $rid, Request $request): JsonResponse {
    $n = (int) $request->query->get('n', 5);
    $payload = $this->service->sample($rid, $n);
    if (isset($payload['error'])) {
      return $this->json($payload, 404);
    }
    // Sample rows are user-facing previews — don't let proxies replay them
    // if access control changes downstream.
    return $this->json($payload, 200, NULL);
  }

  /**
   * Returns distinct values for a column (clamped to 500, with truncated flag).
   */
  public function distinct(string $rid, string $col, Request $request): JsonResponse {
    $limit = (int) $request->query->get('limit', 50);
    $payload = $this->service->distinct($rid, $col, $limit);
    if (isset($payload['error'])) {
      return $this->json($payload, 404);
    }
    return $this->json($payload, 200, NULL);
  }

  /**
   * Builds a JsonResponse with private cache headers.
   *
   * @param array $data
   *   Response payload.
   * @param int $status
   *   HTTP status code.
   * @param int|null $ttl
   *   Max-age in seconds, or NULL for `no-store` (sensitive previews).
   */
  protected function json(array $data, int $status = 200, ?int $ttl = NULL): JsonResponse {
    $response = new JsonResponse($data, $status);
    if ($ttl === NULL || $status >= 400) {
      $response->headers->set('Cache-Control', 'private, no-store');
    }
    else {
      $response->headers->set('Cache-Control', 'private, max-age=' . $ttl);
    }
    return $response;
  }

}
