<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Get column names, types, and descriptions for a datastore resource.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:get_datastore_schema',
  function_name: 'get_datastore_schema',
  name: 'Get datastore schema',
  description: 'Get column names, types, and descriptions for a resource. Use before querying to discover available fields.',
  group: 'dkan_drupal_ai_query',
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
   *
   * @var \Drupal\dkan_query_tools\Tool\DatastoreTools
   */
  protected DatastoreTools $datastoreTools;

  /**
   * The resource id resolver.
   *
   * @var \Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver
   */
  protected ResourceIdResolver $resolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->datastoreTools = $container->get('dkan_query_tools.datastore');
    $instance->resolver = $container->get('dkan_drupal_ai_query.resource_id_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $input = ResourceIdResolver::normalize((string) $this->getContextValue('resource_id'));
    $resolved = $this->resolver->resolve($input);
    if ($resolved === NULL) {
      $this->setOutput(json_encode(['error' => "Could not resolve resource: {$input}"], JSON_UNESCAPED_SLASHES));
      return;
    }
    $result = $this->datastoreTools->getDatastoreSchema(resourceId: $resolved);
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
