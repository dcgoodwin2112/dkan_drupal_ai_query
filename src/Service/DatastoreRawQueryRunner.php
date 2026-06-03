<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Service;

use Drupal\dkan_datastore\Service\DatastoreQuery;
use Drupal\dkan_datastore\Service\Query;
use RootedData\Exception\ValidationException;

/**
 * Executes a DKAN DatastoreQuery payload supplied verbatim by the agent.
 *
 * Backs the `query_datastore_raw` FunctionCall plugin: the agent passes a JSON
 * string in DKAN's documented DatastoreQuery DSL and we hand it to
 * \Drupal\dkan_datastore\Service\Query::runQuery() with no flat-tool transforms.
 * Lives in its own service so it can be unit-tested without booting the
 * FunctionCallBase plugin scaffold.
 *
 * Resource identifiers in `resources[].id` are passed through
 * ResourceIdResolver, so the agent may use either canonical
 * `{identifier}__{version}` form or a fuzzy dataset title.
 */
class DatastoreRawQueryRunner {

  /**
   * Optional factory closure for constructing DatastoreQuery instances.
   *
   * Defaults to `new DatastoreQuery($json)`. Tests inject a custom factory to
   * exercise the validation-error branch without needing the real Opis schema.
   *
   * @var \Closure
   */
  protected \Closure $datastoreQueryFactory;

  public function __construct(
    protected Query $query,
    protected ResourceIdResolver $resolver,
    ?\Closure $datastoreQueryFactory = NULL,
  ) {
    $this->datastoreQueryFactory = $datastoreQueryFactory
      ?? static fn(string $json): DatastoreQuery => new DatastoreQuery($json);
  }

  /**
   * Run a raw DatastoreQuery payload and return the response as an array.
   *
   * Always returns an array, never throws — failures are surfaced as
   * `{error: string, validation_errors?: array}` so the LLM gets a structured
   * payload it can reason about and recover from.
   */
  public function run(string $payload): array {
    $payload = trim($payload);
    if ($payload === '') {
      return ['error' => 'payload is required.'];
    }

    $decoded = json_decode($payload, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
      return ['error' => 'Payload must be a JSON object.'];
    }

    $resolveError = $this->resolveResourceIds($decoded);
    if ($resolveError !== NULL) {
      return $resolveError;
    }

    $rebuilt = json_encode($decoded, JSON_UNESCAPED_SLASHES);
    if ($rebuilt === FALSE) {
      return ['error' => 'Could not re-encode payload.'];
    }

    try {
      $dq = ($this->datastoreQueryFactory)($rebuilt);
      $result = $this->query->runQuery($dq);
    }
    catch (ValidationException $e) {
      return [
        'error' => $e->getMessage(),
        'validation_errors' => $this->formatValidationErrors($e),
      ];
    }
    catch (\InvalidArgumentException $e) {
      return ['error' => $e->getMessage()];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }

    $resultJson = (string) $result;
    if ($resultJson === '') {
      return [];
    }
    $decodedResult = json_decode($resultJson, TRUE);
    return is_array($decodedResult) ? $decodedResult : [];
  }

  /**
   * Resolve fuzzy resource identifiers in-place.
   *
   * Returns a {error} payload when a resource cannot be resolved.
   */
  protected function resolveResourceIds(array &$decoded): ?array {
    if (!isset($decoded['resources'])) {
      return NULL;
    }
    if (!is_array($decoded['resources']) || !array_is_list($decoded['resources'])) {
      return ['error' => 'resources must be an array of {id, alias} objects.'];
    }
    foreach ($decoded['resources'] as $idx => $resource) {
      if (!is_array($resource) || empty($resource['id']) || !is_string($resource['id'])) {
        return ['error' => "resources[$idx] is missing a string id."];
      }
      $original = (string) $resource['id'];
      $resolved = $this->resolver->resolve(ResourceIdResolver::normalize($original));
      if ($resolved === NULL) {
        return ['error' => "Could not resolve resource: $original"];
      }
      $decoded['resources'][$idx]['id'] = $resolved;
      if (empty($resource['alias'])) {
        // DKAN's REST controller defaults to "t" for single-resource queries;
        // mirror that so payloads without an alias still validate.
        $decoded['resources'][$idx]['alias'] = $idx === 0 ? 't' : ('r' . $idx);
      }
    }
    return NULL;
  }

  /**
   * Convert an Opis validation result into a JSON-serializable error list.
   */
  protected function formatValidationErrors(ValidationException $e): array {
    $result = $e->getResult();
    $out = [];
    foreach ($result->getErrors() as $err) {
      $pointer = '/' . implode('/', array_map('strval', $err->dataPointer()));
      $out[] = [
        'pointer' => $pointer === '/' ? '' : $pointer,
        'keyword' => $err->keyword(),
        'args' => $err->keywordArgs(),
      ];
    }
    return $out;
  }

}
