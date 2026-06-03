<?php

namespace Drupal\dkan_drupal_ai_query\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\ai_agents\Event\AgentToolPreExecuteEvent;
use Drupal\dkan_common\DataResource;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dkan_datastore\DatastoreService;
use Drupal\dkan_drupal_ai_query\Service\ArtifactStorage;
use Drupal\dkan_drupal_ai_query\Service\RefusalCollector;
use Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver;
use Drupal\dkan_drupal_ai_query\Service\SystemPromptLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Captures table data and chart specs from tool executions.
 *
 * - For query_datastore / query_datastore_join: writes the parsed result
 *   (when not an error) as a 'data' artifact with full input + provenance so
 *   the UI can render the interactive table, API/SQL preview, and audit
 *   panel.
 * - For the SIMPLE_TABLE_TOOLS set (sample_rows, distinct_values,
 *   search_columns, list_datasets, list_distributions, get_datastore_schema):
 *   writes a slimmer 'data' artifact carrying just the rows, a column hint,
 *   and a stripped provenance block. The UI renders these as table+CSV only
 *   (no API/SQL preview, since those tools don't map to a public datastore
 *   query).
 * - For create_chart: pulls the Vega-Lite spec out of the tool's context
 *   (the LLM-visible result was a stub) and writes it as a 'chart' artifact.
 */
class ArtifactCaptureSubscriber implements EventSubscriberInterface {

  /**
   * Per-tool table-capture config for non-datastore-query tools.
   *
   * Keyed by tool name. Each entry tells captureSimpleData() how to extract
   * rows (`rows_key`), the total-row count (`count_key`), the displayed
   * column order (`columns`, NULL = use row keys as-is), and any reshape
   * to convert the LLM-facing JSON shape into rows of objects.
   */
  /**
   * Tool names that emit an 'aux_tool' artifact instead of a primary table.
   *
   * These tools produce structured-but-non-tabular results that are useful
   * to surface in the UI's "Behind the scenes" disclosure (verifying agent
   * computations, reading data dictionaries, etc.) but don't deserve their
   * own top-level table render.
   */
  protected const AUX_TOOLS = [
    'compute_stats',
    'get_data_dictionary',
    'get_datastore_stats',
    'get_datastore_schema',
    'distinct_values',
  ];

  protected const SIMPLE_TABLE_TOOLS = [
    'sample_rows' => [
      'rows_key' => 'rows',
      'count_key' => 'row_count',
      'columns' => NULL,
      'reshape' => NULL,
    ],
    'search_columns' => [
      'rows_key' => 'matches',
      'count_key' => 'total_matches',
      'columns' => ['dataset_title', 'resource_id', 'column_name', 'column_type', 'matched_in'],
      'reshape' => NULL,
    ],
    'search_datasets' => [
      'rows_key' => 'results',
      'count_key' => 'total',
      'columns' => ['identifier', 'title', 'description', 'distributions'],
      'reshape' => NULL,
    ],
    'list_datasets' => [
      'rows_key' => 'datasets',
      'count_key' => 'total',
      'columns' => ['identifier', 'title', 'description', 'distributions'],
      'reshape' => 'reshapeListDatasets',
    ],
    'list_distributions' => [
      'rows_key' => 'distributions',
      'count_key' => NULL,
      'columns' => ['identifier', 'resource_id', 'title', 'mediaType'],
      'reshape' => NULL,
    ],
  ];

  /**
   * Per-instance cache of resolved table names: resource_id => table_name.
   *
   * @var array
   */
  protected array $tableNameCache = [];

  /**
   * Pre-execute timestamps keyed by thread id, captured for tool-call timing.
   *
   * Pre/finished events bracket each tool synchronously within a thread, so
   * a single slot per thread is enough — overwritten on each pre, consumed
   * (and cleared) on the matching finish.
   *
   * @var array<string, float>
   */
  protected array $pendingStarts = [];

  public function __construct(
    protected ArtifactStorage $artifacts,
    protected LoggerInterface $logger,
    protected ResourceIdResolver $resolver,
    protected DatastoreService $datastoreService,
    protected RefusalCollector $refusals,
    protected ConfigFactoryInterface $configFactory,
    protected ?SystemPromptLoader $systemPromptLoader = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AgentToolPreExecuteEvent::EVENT_NAME => ['onToolPreExecute', 0],
      AgentToolFinishedExecutionEvent::EVENT_NAME => ['onToolFinished', 0],
    ];
  }

  /**
   * Record the start time so the matching finish can compute elapsed ms.
   */
  public function onToolPreExecute(AgentToolPreExecuteEvent $event): void {
    $threadId = $event->getThreadId() ?: $event->getAgentRunnerId();
    if (!$threadId) {
      return;
    }
    $this->pendingStarts[$threadId] = microtime(TRUE);
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

    if (($this->configFactory->get('dkan_drupal_ai_query.settings')->get('debug_log_level') ?? 'off') === 'debug') {
      try {
        $args = $tool->getContextValues();
      }
      catch (\Throwable) {
        $args = [];
      }
      try {
        $output = (string) $tool->getReadableOutput();
      }
      catch (\Throwable) {
        $output = '';
      }
      $this->logger->debug('Tool finished: thread=@t name=@n args=@a output=@o', [
        '@t' => $threadId,
        '@n' => $name,
        '@a' => mb_substr((string) json_encode($args), 0, 1000),
        '@o' => mb_substr($output, 0, 1000),
      ]);
    }

    if ($name === 'query_datastore' || $name === 'query_datastore_join' || $name === 'query_datastore_raw') {
      $this->captureData($threadId, $tool, $name);
    }
    elseif (isset(self::SIMPLE_TABLE_TOOLS[$name])) {
      $this->captureSimpleData($threadId, $tool, $name, self::SIMPLE_TABLE_TOOLS[$name]);
    }
    elseif (in_array($name, self::AUX_TOOLS, TRUE)) {
      $this->captureAuxTool($threadId, $tool, $name);
    }
    elseif ($name === 'create_chart') {
      $this->captureChart($threadId, $tool);
    }
    elseif ($name === 'refuse') {
      $this->captureRefusal($threadId, $tool);
    }

    // Always record a debug snapshot of the tool call so the frontend can
    // rebuild its tool-calls panel when a saved conversation is reloaded.
    $this->captureToolCallSnapshot($threadId, $tool, $name);
  }

  /**
   * Persist a compact debug snapshot of a tool call for history replay.
   *
   * The live UI gets `tool_started` / `tool_finished` events from the
   * ai_agents status timeline, but those are session-scoped and gone once
   * the request ends. Persisting the same shape against the message makes
   * `loadConversation` able to repopulate the debug panel.
   */
  protected function captureToolCallSnapshot(string $threadId, $tool, string $name): void {
    $args = [];
    try {
      foreach ((array) $tool->getContextValues() as $contextName => $value) {
        if ($value === NULL || $value === '') {
          continue;
        }
        $args[$contextName] = $value;
      }
    }
    catch (\Throwable) {
      // Some plugins throw when a required context is unset; an empty
      // input list is fine — the tool name and result still go through.
    }
    try {
      $readable = (string) $tool->getReadableOutput();
    }
    catch (\Throwable) {
      $readable = '';
    }
    $toolInput = $args ? json_encode($args, JSON_UNESCAPED_SLASHES) : '';
    $this->artifacts->append($threadId, [
      'type' => 'tool_call',
      'tool_name' => $name,
      'tool_input' => $toolInput,
      // Cap the persisted result so a large query doesn't bloat the message
      // entity. The summary line in the debug panel only needs the head
      // anyway; the full table is available via the `data` artifact.
      'tool_results' => $readable !== '' ? mb_substr($readable, 0, 4000) : '',
      'telemetry' => $this->buildToolCallTelemetry($threadId, $toolInput, $readable),
    ]);
  }

  /**
   * Build the per-tool-call telemetry block.
   *
   * Adds enough metadata for offline analysis (timing, prompt-version
   * correlation, raw input/output size, error rate) without bloating the
   * persisted artifact with the full output. Read by ad-hoc SQL queries
   * over `dkan_aiq_message.artifacts`.
   */
  protected function buildToolCallTelemetry(string $threadId, string $toolInput, string $readable): array {
    $elapsedMs = NULL;
    if (isset($this->pendingStarts[$threadId])) {
      $elapsedMs = (int) round((microtime(TRUE) - $this->pendingStarts[$threadId]) * 1000);
      unset($this->pendingStarts[$threadId]);
    }
    // Detect a top-level `error` key in the JSON output. Non-JSON output
    // (or output that doesn't decode to an array) is treated as not-an-error;
    // structured tools we care about always emit JSON.
    $errorPresent = FALSE;
    if ($readable !== '') {
      $decoded = json_decode($readable, TRUE);
      if (is_array($decoded) && array_key_exists('error', $decoded)) {
        $errorPresent = TRUE;
      }
    }
    return [
      'execution_time_ms' => $elapsedMs,
      'prompt_version' => $this->systemPromptLoader?->activeVersion(),
      'tool_input_size' => strlen($toolInput),
      'tool_output_size' => strlen($readable),
      'error_present' => $errorPresent,
    ];
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
    if ($toolName === 'query_datastore_raw') {
      // Raw tool's only context is `payload` (the verbatim DSL JSON). The
      // playground branch reads it via `input.payload`; everything else in
      // the loop above is irrelevant for raw and harmlessly absent.
      $inputNames = ['payload'];
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

    // For the raw tool, walk every resource in the payload and build a
    // {hash}__{version} → distribution_uuid map. The playground needs UUIDs
    // because both /api/1/datastore/query (collection) and the per-resource
    // form expect distribution UUIDs in resources[].id, while the agent's
    // payload uses the internal {hash}__{version} form (the runner resolves
    // titles to that form before validation).
    if ($toolName === 'query_datastore_raw' && !empty($input['payload'])) {
      $payloadDecoded = is_string($input['payload']) ? json_decode($input['payload'], TRUE) : NULL;
      $resources = $payloadDecoded['resources'] ?? NULL;
      if (is_array($resources)) {
        $uuidMap = [];
        foreach ($resources as $resource) {
          $id = $resource['id'] ?? NULL;
          if (!is_string($id) || $id === '') {
            continue;
          }
          $resolved = $this->resolver->resolve(ResourceIdResolver::normalize($id));
          if ($resolved === NULL) {
            continue;
          }
          $uuid = $this->resolver->resolveDistributionUuid($resolved);
          if ($uuid !== NULL) {
            $uuidMap[$resolved] = $uuid;
            // Index by the agent's original id too, in case the agent
            // emitted a fuzzy title that resolved to a different string.
            if ($id !== $resolved) {
              $uuidMap[$id] = $uuid;
            }
          }
        }
        if ($uuidMap !== []) {
          $input['distribution_uuid_map'] = $uuidMap;
        }
        if (!empty($resources[0]['id'])) {
          $primary = $this->resolver->resolve(ResourceIdResolver::normalize((string) $resources[0]['id']));
          if ($primary !== NULL) {
            $input['resolved_resource_id'] = $primary;
            if (isset($uuidMap[$primary])) {
              $input['distribution_uuid'] = $uuidMap[$primary];
            }
          }
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
   * Conditions/expressions arrive from the LLM as JSON strings; decoding them
   * lets the UI (and a future LLM-as-judge in Phase 6) reason over structured
   * data rather than re-parsing strings.
   */
  protected function buildQuerySummary(string $toolName, array $input): array {
    if ($toolName === 'query_datastore_raw') {
      $payload = $input['payload'] ?? '';
      $decoded = is_string($payload) ? json_decode($payload, TRUE) : NULL;
      $primaryId = NULL;
      if (is_array($decoded) && !empty($decoded['resources'][0]['id'])) {
        $primaryId = (string) $decoded['resources'][0]['id'];
      }
      return [
        'resource_id' => $primaryId,
        'payload' => is_array($decoded) ? $decoded : NULL,
      ];
    }
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
   * Capture a 'data' artifact for the simple-table tool family.
   *
   * Mirrors captureData() but skips the REST/SQL preview path (those tools
   * don't map to a public datastore query) and produces a stripped
   * provenance block without the query_summary.
   */
  protected function captureSimpleData(string $threadId, $tool, string $toolName, array $cfg): void {
    $raw = $tool->getReadableOutput();
    if (!$raw) {
      return;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || isset($decoded['error'])) {
      return;
    }

    $rows = $decoded[$cfg['rows_key']] ?? [];
    if (!is_array($rows)) {
      return;
    }

    if (!empty($cfg['reshape']) && method_exists($this, $cfg['reshape'])) {
      $rows = $this->{$cfg['reshape']}($rows, $decoded);
    }

    // The simple-table tools accept at most a resource_id; keeping this
    // small lets the UI still surface "what was queried" without dragging
    // in the query_datastore preview panels.
    $input = [];
    foreach (['resource_id', 'column', 'query', 'keyword', 'limit', 'dataset_id', 'page', 'page_size'] as $contextName) {
      try {
        $value = $tool->getContextValue($contextName);
      }
      catch (\Throwable) {
        continue;
      }
      if ($value === NULL || $value === '') {
        continue;
      }
      $input[$contextName] = $value;
    }

    // Resolve resource_id → distribution_uuid so the playground hits the
    // public datastore-query endpoint (which takes the distribution UUID,
    // not the internal {hash}__{version} resource id).
    $this->annotateResourceId($input);

    $totalRows = $cfg['count_key'] !== NULL && isset($decoded[$cfg['count_key']])
      ? (int) $decoded[$cfg['count_key']]
      : count($rows);

    $this->artifacts->append($threadId, [
      'type' => 'data',
      'tool' => $toolName,
      'rows' => $rows,
      'count' => $totalRows,
      'schema' => NULL,
      'columns_hint' => $cfg['columns'] ?? NULL,
      'input' => $input ?: NULL,
      'provenance' => $this->buildSimpleProvenance($toolName, $decoded, count($rows), $totalRows),
    ]);
  }

  /**
   * Resolve resource_id in the input array to its distribution UUID.
   *
   * Mutates $input to add resolved_resource_id and distribution_uuid when a
   * lookup succeeds. Shared by captureSimpleData and captureAuxTool so any
   * tool whose playground rebuild hits /api/1/datastore/query/{distributionId}
   * can find the right id at render time.
   */
  protected function annotateResourceId(array &$input): void {
    if (empty($input['resource_id'])) {
      return;
    }
    $resolved = $this->resolver->resolve(ResourceIdResolver::normalize((string) $input['resource_id']));
    if ($resolved === NULL) {
      return;
    }
    $input['resolved_resource_id'] = $resolved;
    $distributionUuid = $this->resolver->resolveDistributionUuid($resolved);
    if ($distributionUuid !== NULL) {
      $input['distribution_uuid'] = $distributionUuid;
    }
  }

  /**
   * Stripped provenance for simple-table tools.
   *
   * No query_summary — these tools don't carry conditions/expressions/sort.
   * Sanity flags pass through when the tool happens to attach them.
   */
  protected function buildSimpleProvenance(string $toolName, array $decoded, int $returnedRows, int $totalRows): array {
    return [
      'executed_at' => gmdate('c'),
      'tool' => $toolName,
      'row_count' => $returnedRows,
      'total_rows' => $totalRows,
      'sanity_flags' => $decoded['sanity_flags'] ?? NULL,
    ];
  }

  /**
   * Reshape list_datasets' nested distributions to a count cell.
   *
   * The full distributions array would blow out the cell; users browsing the
   * dataset list want to see counts. The full list is a click away via the
   * separate list_distributions tool.
   */
  protected function reshapeListDatasets(array $datasets, array $decoded): array {
    $out = [];
    foreach ($datasets as $dataset) {
      $row = [
        'identifier' => $dataset['identifier'] ?? '',
        'title' => $dataset['title'] ?? '',
        'description' => $dataset['description'] ?? '',
        'distributions' => is_array($dataset['distributions'] ?? NULL) ? count($dataset['distributions']) : 0,
      ];
      $out[] = $row;
    }
    return $out;
  }

  /**
   * Capture an 'aux_tool' artifact for non-tabular tools.
   *
   * Decodes the tool's JSON output and dispatches to a per-tool structurer
   * that produces a UI-friendly shape: headline, structured rows, optional
   * warnings. The original decoded payload is preserved as `raw` so the
   * widget can offer a raw-output disclosure for power users.
   */
  protected function captureAuxTool(string $threadId, $tool, string $toolName): void {
    $raw = $tool->getReadableOutput();
    if (!$raw) {
      return;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded) || isset($decoded['error'])) {
      return;
    }

    $structured = match ($toolName) {
      'compute_stats' => $this->structureComputeStats($decoded),
      'get_data_dictionary' => $this->structureDataDictionary($decoded),
      'get_datastore_stats' => $this->structureDatastoreStats($decoded),
      'get_datastore_schema' => $this->structureSchema($decoded),
      'distinct_values' => $this->structureDistinctValues($decoded),
      default => NULL,
    };
    if ($structured === NULL) {
      return;
    }

    // Capture the tool's accepted inputs so the playground sidebar can
    // rebuild the equivalent REST call. Mirrors captureSimpleData()'s
    // capture set.
    $input = [];
    foreach (['resource_id', 'column', 'columns', 'dataset_id', 'limit'] as $contextName) {
      try {
        $value = $tool->getContextValue($contextName);
      }
      catch (\Throwable) {
        continue;
      }
      if ($value === NULL || $value === '') {
        continue;
      }
      $input[$contextName] = $value;
    }
    $this->annotateResourceId($input);

    $this->artifacts->append($threadId, [
      'type' => 'aux_tool',
      'tool' => $toolName,
      'executed_at' => gmdate('c'),
      'structured' => $structured,
      'raw' => $decoded,
      'input' => $input ?: NULL,
    ]);
  }

  /**
   * Structure a compute_stats payload for the UI.
   *
   * Polymorphic `value` field (number for median/stddev, object for
   * quartiles) is normalised to a display string here so the JS render
   * path doesn't need branching logic per operation type.
   */
  protected function structureComputeStats(array $decoded): array {
    $rowCount = (int) ($decoded['row_count'] ?? 0);
    $resultsRaw = is_array($decoded['results'] ?? NULL) ? $decoded['results'] : [];
    $rows = [];
    foreach ($resultsRaw as $r) {
      if (!is_array($r)) {
        continue;
      }
      $value = $r['value'] ?? NULL;
      if (is_array($value)) {
        // Quartiles: {q1, q2, q3, iqr}.
        $parts = [];
        foreach (['q1', 'q2', 'q3', 'iqr'] as $k) {
          if (isset($value[$k])) {
            $parts[] = strtoupper($k) === 'IQR'
              ? 'IQR=' . $value[$k]
              : $k . '=' . $value[$k];
          }
        }
        $valueDisplay = implode(', ', $parts);
      }
      else {
        $valueDisplay = $value === NULL ? '' : (string) $value;
      }
      // ComputeStatsTool emits `op` for the operation name; older payloads
      // used `type`. Read both for safety.
      $rows[] = [
        'operation' => (string) ($r['op'] ?? ($r['type'] ?? '')),
        'column' => (string) ($r['column'] ?? ($r['columns'] ?? '')),
        'value' => $valueDisplay,
        'rows_skipped' => (int) ($r['rows_skipped'] ?? 0),
      ];
    }
    return [
      'headline' => count($rows) . ' statistic' . (count($rows) !== 1 ? 's' : '') . ' computed across ' . $rowCount . ' row' . ($rowCount !== 1 ? 's' : ''),
      'warnings' => array_values(array_filter((array) ($decoded['warnings'] ?? []))),
      'rows' => $rows,
    ];
  }

  /**
   * Structure a get_data_dictionary payload for the UI.
   *
   * Flattens the nested {resource_id => dictionary} map into an indexed
   * array of {title, url, fields} objects so the JS can iterate and render
   * a section per dictionary without map-key juggling.
   */
  protected function structureDataDictionary(array $decoded): array {
    $dictsRaw = is_array($decoded['dictionaries'] ?? NULL) ? $decoded['dictionaries'] : [];
    $dicts = [];
    $totalFields = 0;
    foreach ($dictsRaw as $resourceId => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $fieldsRaw = is_array($entry['fields'] ?? NULL) ? $entry['fields'] : [];
      $fields = [];
      foreach ($fieldsRaw as $f) {
        if (!is_array($f)) {
          continue;
        }
        $fields[] = [
          'name' => (string) ($f['name'] ?? ''),
          'title' => (string) ($f['title'] ?? ''),
          'type' => (string) ($f['type'] ?? ''),
          'description' => (string) ($f['description'] ?? ''),
        ];
      }
      $dicts[] = [
        'resource_id' => (string) $resourceId,
        'title' => (string) ($entry['title'] ?? $resourceId),
        'url' => (string) ($entry['url'] ?? ''),
        'fields' => $fields,
      ];
      $totalFields += count($fields);
    }
    return [
      'headline' => $totalFields . ' field definition' . ($totalFields !== 1 ? 's' : '') . ' across ' . count($dicts) . ' resource' . (count($dicts) !== 1 ? 's' : ''),
      'dictionaries' => $dicts,
    ];
  }

  /**
   * Structure a get_datastore_stats payload for the UI.
   */
  protected function structureDatastoreStats(array $decoded): array {
    $totalRows = (int) ($decoded['total_rows'] ?? 0);
    $columnsRaw = is_array($decoded['columns'] ?? NULL) ? $decoded['columns'] : [];
    $columns = [];
    foreach ($columnsRaw as $c) {
      if (!is_array($c)) {
        continue;
      }
      $columns[] = [
        'name' => (string) ($c['name'] ?? ''),
        'type' => (string) ($c['type'] ?? ''),
        'null_count' => (int) ($c['null_count'] ?? 0),
        'distinct_count' => (int) ($c['distinct_count'] ?? 0),
        'min' => $c['min'] ?? '',
        'max' => $c['max'] ?? '',
      ];
    }
    return [
      'headline' => 'Stats for ' . count($columns) . ' column' . (count($columns) !== 1 ? 's' : '') . ' in a table of ' . $totalRows . ' row' . ($totalRows !== 1 ? 's' : ''),
      'total_rows' => $totalRows,
      'columns' => $columns,
    ];
  }

  /**
   * Structure a get_datastore_schema payload for the UI.
   */
  protected function structureSchema(array $decoded): array {
    $columnsRaw = is_array($decoded['columns'] ?? NULL) ? $decoded['columns'] : [];
    $columns = [];
    foreach ($columnsRaw as $c) {
      if (!is_array($c)) {
        continue;
      }
      $columns[] = [
        'name' => (string) ($c['name'] ?? ''),
        'type' => (string) ($c['type'] ?? ''),
        'description' => (string) ($c['description'] ?? ''),
      ];
    }
    return [
      'headline' => count($columns) . ' column' . (count($columns) !== 1 ? 's' : ''),
      'columns' => $columns,
    ];
  }

  /**
   * Structure a distinct_values payload for the UI.
   */
  protected function structureDistinctValues(array $decoded): array {
    $values = is_array($decoded['values'] ?? NULL) ? $decoded['values'] : [];
    $column = (string) ($decoded['column'] ?? '');
    $count = (int) ($decoded['value_count'] ?? count($values));
    $truncated = !empty($decoded['truncated']);
    $headline = $count . ' distinct value' . ($count !== 1 ? 's' : '');
    if ($column !== '') {
      $headline .= " for '$column'";
    }
    if ($truncated) {
      $headline .= ' (truncated)';
    }
    return [
      'headline' => $headline,
      'column' => $column,
      'truncated' => $truncated,
      'values' => array_values($values),
    ];
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
