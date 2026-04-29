<?php

namespace Drupal\dkan_drupal_ai_query\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\common\DataResource;
use Drupal\datastore\DatastoreService;
use Drupal\dkan_drupal_ai_query\Service\ArtifactStorage;
use Drupal\dkan_drupal_ai_query\Service\RefusalCollector;
use Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Captures table data and chart specs from tool executions.
 *
 * - For query_datastore / query_datastore_join: writes the parsed result
 *   (when not an error) as a 'data' artifact. The poll endpoint surfaces it
 *   so the UI can render an interactive table without bloating the LLM
 *   conversation with the full payload.
 * - For create_chart: pulls the Vega-Lite spec out of the tool's context
 *   (the LLM-visible result was a stub) and writes it as a 'chart' artifact.
 */
class ArtifactCaptureSubscriber implements EventSubscriberInterface {

  /**
   * Per-instance cache of resolved table names: resource_id => table_name.
   *
   * @var array
   */
  protected array $tableNameCache = [];

  public function __construct(
    protected ArtifactStorage $artifacts,
    protected LoggerInterface $logger,
    protected ResourceIdResolver $resolver,
    protected DatastoreService $datastoreService,
    protected RefusalCollector $refusals,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentToolFinishedExecutionEvent::EVENT_NAME => ['onToolFinished', 0],
    ];
  }

  /**
   * Capture data table or chart spec when one of our tools finishes.
   */
  public function onToolFinished(AgentToolFinishedExecutionEvent $event): void {
    // ai_agents only auto-generates a thread id when progressTracking is on.
    // CLI runs (eval, future cron) disable that to avoid PrivateTempStore's
    // session requirement, so threadId is NULL there. Fall back to the
    // runner id, which the caller always sets. The controller wires both
    // to the same value, so behaviour is unchanged for HTTP requests.
    $threadId = $event->getThreadId() ?: $event->getAgentRunnerId();
    if (!$threadId) {
      return;
    }
    $tool = $event->getTool();
    $name = $tool->getFunctionName();

    if ($name === 'query_datastore' || $name === 'query_datastore_join') {
      $this->captureData($threadId, $tool, $name);
      return;
    }
    if ($name === 'create_chart') {
      $this->captureChart($threadId, $tool);
      return;
    }
    if ($name === 'refuse') {
      $this->captureRefusal($threadId, $tool);
    }
  }

  /**
   * Capture a structured refusal payload into RefusalCollector.
   */
  protected function captureRefusal(string $threadId, $tool): void {
    $raw = $tool->getReadableOutput();
    if (!$raw) {
      return;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || empty($decoded['refused'])) {
      return;
    }
    $this->refusals->record($threadId, $decoded);
    // Also surface to the UI artifact stream when a session is available.
    $this->artifacts->append($threadId, [
      'type' => 'refusal',
      'reason_category' => $decoded['reason_category'] ?? 'other',
      'explanation' => $decoded['explanation'] ?? '',
      'datasets_searched' => $decoded['datasets_searched'] ?? [],
    ]);
  }

  /**
   * Decode the tool's JSON output and append a data artifact.
   */
  protected function captureData(string $threadId, $tool, string $toolName): void {
    $raw = $tool->getReadableOutput();
    if (!$raw) {
      return;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || isset($decoded['error'])) {
      return;
    }

    // Capture the original tool inputs so the UI can rebuild the equivalent
    // REST API call and SQL statement. getContextValue() throws when a
    // context isn't set, so guard each read.
    $inputNames = [
      'resource_id',
      'columns',
      'conditions',
      'sort_field',
      'sort_direction',
      'limit',
      'offset',
      'expressions',
      'groupings',
    ];
    if ($toolName === 'query_datastore_join') {
      $inputNames[] = 'join_resource_id';
      $inputNames[] = 'join_on';
    }
    $input = [];
    foreach ($inputNames as $name) {
      try {
        $value = $tool->getContextValue($name);
      }
      catch (\Throwable $e) {
        continue;
      }
      if ($value === NULL || $value === '') {
        continue;
      }
      $input[$name] = $value;
    }

    // Resolve resource ids to their canonical "{identifier}__{version}" form
    // so the API / SQL preview panels render the same identifiers the
    // datastore query actually used. The LLM may have passed a fuzzy
    // dataset title or a hex-corrupted id; the resolver normalizes both.
    if (!empty($input['resource_id'])) {
      $resolved = $this->resolver->resolve(ResourceIdResolver::normalize((string) $input['resource_id']));
      if ($resolved !== NULL) {
        $input['resolved_resource_id'] = $resolved;
        // The public datastore query endpoint takes the distribution UUID,
        // not the internal {hash}__{version} resource id, so capture both.
        $distributionUuid = $this->resolver->resolveDistributionUuid($resolved);
        if ($distributionUuid !== NULL) {
          $input['distribution_uuid'] = $distributionUuid;
        }
        $tableName = $this->resolveTableName($resolved);
        if ($tableName !== NULL) {
          $input['table_name'] = $tableName;
        }
      }
    }
    if (!empty($input['join_resource_id'])) {
      $resolvedJoin = $this->resolver->resolve(ResourceIdResolver::normalize((string) $input['join_resource_id']));
      if ($resolvedJoin !== NULL) {
        $input['resolved_join_resource_id'] = $resolvedJoin;
        $joinUuid = $this->resolver->resolveDistributionUuid($resolvedJoin);
        if ($joinUuid !== NULL) {
          $input['join_distribution_uuid'] = $joinUuid;
        }
        $joinTable = $this->resolveTableName($resolvedJoin);
        if ($joinTable !== NULL) {
          $input['join_table_name'] = $joinTable;
        }
      }
    }

    $rows = $decoded['results'] ?? [];
    $totalRows = $decoded['total_rows']
      ?? $decoded['count']
      ?? count($rows);
    $this->artifacts->append($threadId, [
      'type' => 'data',
      'tool' => $toolName,
      'rows' => $rows,
      'count' => $totalRows,
      'schema' => $decoded['schema'] ?? NULL,
      'query' => $decoded['query'] ?? NULL,
      'input' => $input ?: NULL,
      'provenance' => $this->buildProvenance($toolName, $input, $decoded, count($rows), (int) $totalRows),
    ]);
  }

  /**
   * Build the provenance block that travels with each data artifact.
   *
   * Auditable trail of one tool call: when it ran, what query shape was
   * executed, how many rows came back, and any sanity flags the datastore
   * tools attached. The widget renders this as an expandable panel.
   */
  protected function buildProvenance(string $toolName, array $input, array $decoded, int $returnedRows, int $totalRows): array {
    return [
      'executed_at' => gmdate('c'),
      'tool' => $toolName,
      'row_count' => $returnedRows,
      'total_rows' => $totalRows,
      'sanity_flags' => $decoded['sanity_flags'] ?? NULL,
      'query_summary' => $this->buildQuerySummary($toolName, $input),
    ];
  }

  /**
   * Strip the input down to the structured-query fields, decoding JSON ones.
   *
   * conditions/expressions arrive from the LLM as JSON strings; decoding them
   * lets the UI (and a future LLM-as-judge in Phase 6) reason over structured
   * data rather than re-parsing strings.
   */
  protected function buildQuerySummary(string $toolName, array $input): array {
    $summary = [
      'resource_id' => $input['resolved_resource_id'] ?? $input['resource_id'] ?? NULL,
    ];
    foreach (['columns', 'sort_field', 'sort_direction', 'groupings'] as $key) {
      if (isset($input[$key]) && $input[$key] !== '') {
        $summary[$key] = $input[$key];
      }
    }
    foreach (['limit', 'offset'] as $key) {
      if (isset($input[$key])) {
        $summary[$key] = (int) $input[$key];
      }
    }
    foreach (['conditions', 'expressions'] as $key) {
      if (isset($input[$key]) && is_string($input[$key]) && $input[$key] !== '') {
        $decoded = json_decode($input[$key], TRUE);
        $summary[$key] = is_array($decoded) ? $decoded : $input[$key];
      }
    }
    if ($toolName === 'query_datastore_join') {
      $summary['join_resource_id'] = $input['resolved_join_resource_id'] ?? $input['join_resource_id'] ?? NULL;
      if (isset($input['join_on'])) {
        $summary['join_on'] = $input['join_on'];
      }
    }
    return $summary;
  }

  /**
   * Resolve a resource id to its physical datastore table name.
   *
   * Returns "datastore_<md5>" or NULL when no datastore storage exists for
   * the given "{identifier}__{version}" resource id.
   */
  protected function resolveTableName(string $resolvedResourceId): ?string {
    if (array_key_exists($resolvedResourceId, $this->tableNameCache)) {
      return $this->tableNameCache[$resolvedResourceId];
    }
    try {
      [$id, $version] = DataResource::getIdentifierAndVersion($resolvedResourceId);
      $storage = $this->datastoreService->getStorage($id, $version);
      return $this->tableNameCache[$resolvedResourceId] = $storage ? $storage->getTableName() : NULL;
    }
    catch (\Throwable $e) {
      return $this->tableNameCache[$resolvedResourceId] = NULL;
    }
  }

  /**
   * Pull the spec from the tool context, normalize, append as chart artifact.
   */
  protected function captureChart(string $threadId, $tool): void {
    $spec = $tool->getContextValue('spec');
    if (!$spec) {
      return;
    }
    if (is_string($spec)) {
      $decoded = json_decode($spec, TRUE);
      if (!is_array($decoded)) {
        $this->logger->warning('Could not decode chart spec for thread @t.', ['@t' => $threadId]);
        return;
      }
      $spec = $decoded;
    }
    if (!is_array($spec)) {
      return;
    }
    $this->artifacts->append($threadId, [
      'type' => 'chart',
      'spec' => $spec,
    ]);
  }

}
