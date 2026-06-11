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
 * Return the first N rows of a datastore resource for orientation.
 */
#[FunctionCall(
  id: 'dkan_ai_query:sample_rows',
  function_name: 'sample_rows',
  name: 'Sample rows',
  description: 'Return the first N rows of a datastore resource (sorted by record_number ascending). Useful before querying to learn cell shapes, code values, and units.',
  group: 'dkan_ai_query',
  context_definitions: [
    'resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Resource ID'),
      description: new TranslatableMarkup('In identifier__version form OR a dataset title for fuzzy lookup.'),
      required: TRUE,
    ),
    'n' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Row count'),
      description: new TranslatableMarkup('Number of rows to return (1-50, default 5).'),
      required: FALSE,
    ),
  ],
)]
class SampleRowsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The datastore tools.
   */
  protected DatastoreTools $datastoreTools;

  /**
   * The resource id resolver.
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
    $n = (int) ($this->getContextValue('n') ?: 5);
    $result = $this->datastoreTools->sampleRows(resourceId: $resolved, n: $n);
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
