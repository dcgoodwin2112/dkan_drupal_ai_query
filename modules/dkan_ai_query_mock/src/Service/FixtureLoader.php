<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query_mock\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\dkan_harvest\HarvestService;
use Drupal\dkan_metastore\MetastoreService;
use Psr\Log\LoggerInterface;

/**
 * Registers, imports, resolves, and tears down the parks fixture dataset.
 *
 * Mirrors DKAN's own sample_content service but drains the import queues
 * synchronously so installation is one drush invocation rather than
 * "register and then run cron a few times".
 */
class FixtureLoader {

  public const HARVEST_PLAN_ID = 'dkan_aiq_mock_fixture';
  public const DATASET_IDENTIFIER = 'a1b2c3d4-e5f6-4789-a0b1-c2d3e4f50001';
  public const DICTIONARY_IDENTIFIER = 'a1b2c3d4-e5f6-4789-a0b1-c2d3e4f50002';
  public const STATE_RESOURCE_ID = 'dkan_ai_query_mock.fixture_resource_id';

  /**
   * Absolute path to the mock submodule.
   */
  private string $modulePath;

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly HarvestService $harvestService,
    private readonly MetastoreService $metastoreService,
    private readonly QueueFactory $queueFactory,
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
    string $appRoot,
  ) {
    $this->modulePath = $appRoot . '/' . $moduleExtensionList->getPath('dkan_ai_query_mock');
  }

  /**
   * Installs the fixture and returns the resolved resource id.
   *
   * Detokenizes the data.json template, registers the harvest plan, runs it,
   * and drains the localize and datastore queues. Idempotent.
   *
   * @return string|null
   *   Resolved {identifier}__{version} resource id, or NULL if no distribution
   *   was localized (e.g. on first install before queue draining).
   */
  public function install(): ?string {
    $this->writeFixtureJson();
    $this->installDataDictionary();

    if (!$this->harvestService->getHarvestPlanObject(self::HARVEST_PLAN_ID)) {
      $plan = $this->loadHarvestPlan();
      $this->harvestService->registerHarvest($plan);
      $this->logger->info('Registered harvest plan: @id', ['@id' => self::HARVEST_PLAN_ID]);
    }
    else {
      $this->logger->info('Harvest plan @id already registered.', ['@id' => self::HARVEST_PLAN_ID]);
    }

    $runResult = $this->harvestService->runHarvest(self::HARVEST_PLAN_ID);
    $this->logger->info('Harvest run completed: @run', [
      '@run' => $runResult['identifier'] ?? '(no run id)',
    ]);

    // Workers cascade: localize -> datastore -> post-import. Loop until every
    // queue is empty in the same pass so cascades don't strand items.
    do {
      $processed = 0;
      $processed += $this->drainQueue('localize_import');
      $processed += $this->drainQueue('datastore_import');
      $processed += $this->drainQueue('post_import');
    } while ($processed > 0);

    $resourceId = $this->resolveResourceId();
    if ($resourceId !== NULL) {
      $this->state->set(self::STATE_RESOURCE_ID, $resourceId);
    }
    return $resourceId;
  }

  /**
   * Reverts the harvest, deregisters the plan, and drops the datastore table.
   */
  public function remove(): void {
    if ($this->harvestService->getHarvestPlanObject(self::HARVEST_PLAN_ID)) {
      $this->harvestService->revertHarvest(self::HARVEST_PLAN_ID);
      $this->harvestService->deregisterHarvest(self::HARVEST_PLAN_ID);
      $this->logger->info('Deregistered harvest plan: @id', ['@id' => self::HARVEST_PLAN_ID]);
    }
    $this->dropFixtureDatastoreTable();
    $this->deleteFixtureJson();
    $this->removeDataDictionary();
    $this->state->delete(self::STATE_RESOURCE_ID);
  }

  /**
   * Resolves the dataset's primary resource id from the metastore.
   *
   * Returns the canonical {identifier}__{version} resource id for the fixture
   * dataset's distribution, or NULL if no localized resource has been
   * registered yet.
   */
  public function resolveResourceId(): ?string {
    try {
      $dataset = $this->metastoreService->get('dataset', self::DATASET_IDENTIFIER);
    }
    catch (\Throwable $e) {
      return NULL;
    }
    $payload = json_decode((string) $dataset);
    $distributions = $payload->{'%Ref:distribution'} ?? [];
    foreach ($distributions as $distribution) {
      $downloadRefs = $distribution->data->{'%Ref:downloadURL'} ?? [];
      foreach ($downloadRefs as $ref) {
        $identifier = $ref->data->identifier ?? NULL;
        $version = $ref->data->version ?? NULL;
        if ($identifier && $version) {
          return $identifier . '__' . $version;
        }
      }
    }
    return NULL;
  }

  /**
   * Returns whether the fixture harvest plan is registered.
   */
  public function isInstalled(): bool {
    return $this->harvestService->getHarvestPlanObject(self::HARVEST_PLAN_ID) !== NULL;
  }

  /**
   * Posts the data-dictionary metastore item if not already present.
   *
   * The dictionary types numeric columns so sorts and aggregates work; without
   * it every column reads back as text and `sort_field=recreation_visits desc`
   * sorts alphabetically.
   */
  private function installDataDictionary(): void {
    try {
      $this->metastoreService->get('data-dictionary', self::DICTIONARY_IDENTIFIER);
      $this->logger->info('Data dictionary @id already exists.', ['@id' => self::DICTIONARY_IDENTIFIER]);
      return;
    }
    catch (\Throwable $e) {
      // Falls through to create.
    }
    $path = $this->modulePath . '/fixtures/fixture_dictionary.json';
    $json = file_get_contents($path);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not read fixture dictionary at: ' . $path);
    }
    $payload = $this->metastoreService->getValidMetadataFactory()->get($json, 'data-dictionary');
    $this->metastoreService->post('data-dictionary', $payload);
    $this->logger->info('Posted data dictionary @id.', ['@id' => self::DICTIONARY_IDENTIFIER]);
  }

  /**
   * Removes the data-dictionary metastore item.
   */
  private function removeDataDictionary(): void {
    try {
      $this->metastoreService->delete('data-dictionary', self::DICTIONARY_IDENTIFIER);
      $this->logger->info('Deleted data dictionary @id.', ['@id' => self::DICTIONARY_IDENTIFIER]);
    }
    catch (\Throwable $e) {
      // Already absent or in an inconsistent state — non-fatal.
    }
  }

  /**
   * Detokenizes fixture.template.json into a writable fixture.json sibling.
   */
  private function writeFixtureJson(): string {
    $template = $this->modulePath . '/fixtures/fixture.template.json';
    $target = $this->modulePath . '/fixtures/fixture.json';
    $content = file_get_contents($template);
    if ($content === FALSE) {
      throw new \RuntimeException('Could not read fixture template at: ' . $template);
    }
    $detokenized = str_replace('<!*path*!>', $this->modulePath . '/fixtures/files', $content);
    if (file_put_contents($target, $detokenized) === FALSE) {
      throw new \RuntimeException('Could not write fixture.json at: ' . $target);
    }
    return $target;
  }

  /**
   * Loads harvest_plan.json with the extract URI rewritten to absolute.
   *
   * Mirrors DKAN sample_content's pattern: the on-disk plan stores a relative
   * URI (e.g. /fixture.json) and we prefix the absolute module path here.
   */
  private function loadHarvestPlan(): object {
    $planPath = $this->modulePath . '/fixtures/harvest_plan.json';
    $json = file_get_contents($planPath);
    if ($json === FALSE) {
      throw new \RuntimeException('Could not read harvest plan at: ' . $planPath);
    }
    $plan = json_decode($json);
    if (!is_object($plan)) {
      throw new \RuntimeException('Invalid harvest plan JSON at: ' . $planPath);
    }
    $plan->extract->uri = 'file://' . $this->modulePath . '/fixtures' . $plan->extract->uri;
    return $plan;
  }

  /**
   * Drains a queue worker until empty and returns the number of items run.
   */
  private function drainQueue(string $queueName): int {
    if (!$this->queueWorkerManager->hasDefinition($queueName)) {
      $this->logger->info('Queue worker @name is not defined; skipping.', ['@name' => $queueName]);
      return 0;
    }
    $queue = $this->queueFactory->get($queueName);
    $worker = $this->queueWorkerManager->createInstance($queueName);
    $processed = 0;
    while ($item = $queue->claimItem(0)) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
        $this->logger->error('Queue @name failed on item: @msg', [
          '@name' => $queueName,
          '@msg' => $e->getMessage(),
        ]);
        throw $e;
      }
    }
    if ($processed > 0) {
      $this->logger->info('Drained @count items from queue @name.', [
        '@count' => $processed,
        '@name' => $queueName,
      ]);
    }
    return $processed;
  }

  /**
   * Drops the datastore table for the fixture's resource, if present.
   *
   * Harvest revert removes the metastore item but does not always drop the
   * datastore table; do it explicitly so :remove leaves a clean slate.
   */
  private function dropFixtureDatastoreTable(): void {
    $resourceId = $this->resolveResourceId();
    if ($resourceId === NULL) {
      return;
    }
    $tableName = 'datastore_' . md5($resourceId);
    if ($this->database->schema()->tableExists($tableName)) {
      $this->database->schema()->dropTable($tableName);
      $this->logger->info('Dropped datastore table @t.', ['@t' => $tableName]);
    }
  }

  /**
   * Removes fixture.json (the detokenized copy). Leaves the template in place.
   */
  private function deleteFixtureJson(): void {
    $target = $this->modulePath . '/fixtures/fixture.json';
    if (file_exists($target)) {
      unlink($target);
    }
  }

}
