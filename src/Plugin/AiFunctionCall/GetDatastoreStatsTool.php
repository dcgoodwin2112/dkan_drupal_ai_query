<?php

namespace Drupal\dkan_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_ai_query\Service\ResourceIdResolver;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Per-column statistics for a datastore resource.
 */
#[FunctionCall(
  id: 'dkan_ai_query:get_datastore_stats',
  function_name: 'get_datastore_stats',
  name: 'Get datastore stats',
  description: 'Get per-column statistics: null count, distinct count, min, max, and total row count. Use to understand data quality and distribution before querying.',
  group: 'dkan_ai_query',
  context_definitions: [
    'resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Resource ID'),
      description: new TranslatableMarkup('In identifier__version form OR a dataset title for fuzzy lookup.'),
      required: TRUE,
    ),
    'columns' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Columns'),
      description: new TranslatableMarkup('Comma-separated column names to analyze (omit for all).'),
      required: FALSE,
    ),
  ],
)]
class GetDatastoreStatsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The datastore tools.
   *
   * @var \Drupal\dkan_query_tools\Tool\DatastoreTools
   */
  protected DatastoreTools $datastoreTools;

  /**
   * The resource id resolver.
   *
   * @var \Drupal\dkan_ai_query\Service\ResourceIdResolver
   */
  protected ResourceIdResolver $resolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->datastoreTools = $container->get('dkan_query_tools.datastore');
    $instance->resolver = $container->get('dkan_ai_query.resource_id_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $input = ResourceIdResolver::normalize((string) $this->getContextValue('resource_id'));
    if ($input === '') {
      $this->setOutput(json_encode([
        'error' => 'resource_id is required. Call find_dataset_resources("title") or list_datasets() first to discover one.',
      ], JSON_UNESCAPED_SLASHES));
      return;
    }
    $resolved = $this->resolver->resolve($input);
    if ($resolved === NULL) {
      $this->setOutput(json_encode(['error' => "Could not resolve resource: {$input}"], JSON_UNESCAPED_SLASHES));
      return;
    }
    $result = $this->datastoreTools->getDatastoreStats(
      resourceId: $resolved,
      columns: $this->getContextValue('columns') ?: NULL,
    );
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
