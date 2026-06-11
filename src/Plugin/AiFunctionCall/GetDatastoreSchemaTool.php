<?php

namespace Drupal\dkan_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_ai_query\Service\DatasetCaveatRegistry;
use Drupal\dkan_ai_query\Service\ResourceIdResolver;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Get column names, types, and descriptions for a datastore resource.
 */
#[FunctionCall(
  id: 'dkan_ai_query:get_datastore_schema',
  function_name: 'get_datastore_schema',
  name: 'Get datastore schema',
  description: 'Get column names, types, and descriptions for a resource. Use before querying to discover available fields.',
  group: 'dkan_ai_query',
  context_definitions: [
    'resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Resource ID'),
      description: new TranslatableMarkup('In identifier__version form OR a dataset title for fuzzy lookup.'),
      required: TRUE,
    ),
  ],
)]
class GetDatastoreSchemaTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The datastore tools.
   */
  protected DatastoreTools $datastoreTools;

  /**
   * The resource id resolver.
   */
  protected ResourceIdResolver $resolver;

  /**
   * The dataset caveat registry.
   */
  protected DatasetCaveatRegistry $caveats;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->datastoreTools = $container->get('dkan_query_tools.datastore');
    $instance->resolver = $container->get('dkan_ai_query.resource_id_resolver');
    $instance->caveats = $container->get('dkan_ai_query.dataset_caveat_registry');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $input = ResourceIdResolver::normalize((string) $this->getContextValue('resource_id'));
    if ($input === '') {
      // Catches the case where the LLM emits an empty argument list. A blank
      // "Could not resolve resource:" error is opaque; this hints at the
      // discovery tools the agent should reach for instead.
      $this->setOutput(json_encode([
        'error' => 'resource_id is required. Call find_dataset_resources("title") or list_datasets() to discover one before calling get_datastore_schema.',
      ], JSON_UNESCAPED_SLASHES));
      return;
    }
    $resolved = $this->resolver->resolve($input);
    if ($resolved === NULL) {
      $this->setOutput(json_encode(['error' => "Could not resolve resource: {$input}"], JSON_UNESCAPED_SLASHES));
      return;
    }
    $result = $this->datastoreTools->getDatastoreSchema(resourceId: $resolved);
    $datasetUuid = $this->resolver->resolveDatasetUuid($resolved);
    if ($datasetUuid !== NULL) {
      $columnCaveats = $this->caveats->getColumnCaveats($datasetUuid);
      if ($columnCaveats && !empty($result['columns'])) {
        foreach ($result['columns'] as &$col) {
          if (isset($columnCaveats[$col['name']])) {
            $col['caveat'] = $columnCaveats[$col['name']];
          }
        }
        unset($col);
      }
      $caveats = $this->caveats->getCaveats($datasetUuid);
      if ($caveats) {
        // Top-level dataset caveats minus column_caveats (already inlined).
        $datasetCaveats = $caveats;
        unset($datasetCaveats['column_caveats']);
        if ($datasetCaveats) {
          $result['dataset_caveats'] = $datasetCaveats;
        }
      }
    }
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
