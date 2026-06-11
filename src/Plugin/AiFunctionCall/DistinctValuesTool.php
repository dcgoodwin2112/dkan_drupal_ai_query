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
 * Return distinct values of a column to learn its code list / domain.
 */
#[FunctionCall(
  id: 'dkan_ai_query:distinct_values',
  function_name: 'distinct_values',
  name: 'Distinct values',
  description: 'Return distinct values of a column. Use to learn the exact code list / enum domain before filtering. Returns up to limit values; sets truncated=true when more exist.',
  group: 'dkan_ai_query',
  context_definitions: [
    'resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Resource ID'),
      description: new TranslatableMarkup('In identifier__version form OR a dataset title for fuzzy lookup.'),
      required: TRUE,
    ),
    'column' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Column'),
      description: new TranslatableMarkup('Column name to enumerate.'),
      required: TRUE,
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Max distinct values to return (1-500, default 50).'),
      required: FALSE,
    ),
  ],
)]
class DistinctValuesTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

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
    $column = (string) $this->getContextValue('column');
    $limit = (int) ($this->getContextValue('limit') ?: 50);
    $result = $this->datastoreTools->distinctValues(
      resourceId: $resolved,
      column: $column,
      limit: $limit,
    );
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
