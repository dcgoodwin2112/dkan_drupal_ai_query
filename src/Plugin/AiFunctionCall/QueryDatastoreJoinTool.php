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
 * Join and query two DKAN datastore resources.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:query_datastore_join',
  function_name: 'query_datastore_join',
  name: 'Query datastore (join)',
  description: 'Join and query two datastore resources. Primary aliased as "t", joined as "j". Qualify columns with alias: "t.state,j.rate". Add "resource":"j" to a condition to filter the joined table.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Primary resource ID'),
      description: new TranslatableMarkup('Resource ID (identifier__version) OR a dataset title for fuzzy lookup.'),
      required: TRUE,
    ),
    'join_resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Join resource ID'),
      description: new TranslatableMarkup('Resource ID to join with.'),
      required: TRUE,
    ),
    'join_on' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Join on'),
      description: new TranslatableMarkup('Join condition, e.g. "state=state_abbreviation".'),
      required: TRUE,
    ),
    'columns' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Columns'),
      description: new TranslatableMarkup('Comma-separated columns with alias prefix: "t.state,j.rate".'),
      required: FALSE,
    ),
    'conditions' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Conditions'),
      description: new TranslatableMarkup('JSON array of conditions; add "resource":"j" to filter the joined table.'),
      required: FALSE,
    ),
    'sort_field' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Sort field'),
      description: new TranslatableMarkup('Column to sort by (with optional alias prefix).'),
      required: FALSE,
    ),
    'sort_direction' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Sort direction'),
      description: new TranslatableMarkup('asc or desc.'),
      required: FALSE,
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Max rows (1-500, default 100).'),
      required: FALSE,
    ),
    'offset' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Offset'),
      description: new TranslatableMarkup('Rows to skip.'),
      required: FALSE,
    ),
    'expressions' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Expressions'),
      description: new TranslatableMarkup('JSON array of aggregate expressions.'),
      required: FALSE,
    ),
    'groupings' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Groupings'),
      description: new TranslatableMarkup('Comma-separated GROUP BY columns with alias prefix.'),
      required: FALSE,
    ),
  ],
)]
class QueryDatastoreJoinTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The dkan_query_tools datastore tools service.
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
    $resourceIdInput = ResourceIdResolver::normalize((string) $this->getContextValue('resource_id'));
    $resolved = $this->resolver->resolve($resourceIdInput);
    if ($resolved === NULL) {
      $this->setOutput(json_encode(['error' => "Could not resolve primary resource: {$resourceIdInput}"], JSON_UNESCAPED_SLASHES));
      return;
    }
    $joinInput = ResourceIdResolver::normalize((string) $this->getContextValue('join_resource_id'));
    $resolvedJoin = $this->resolver->resolve($joinInput);
    if ($resolvedJoin === NULL) {
      $this->setOutput(json_encode(['error' => "Could not resolve join resource: {$joinInput}"], JSON_UNESCAPED_SLASHES));
      return;
    }

    $result = $this->datastoreTools->queryDatastoreJoin(
      resourceId: $resolved,
      joinResourceId: $resolvedJoin,
      joinOn: (string) $this->getContextValue('join_on'),
      columns: $this->getContextValue('columns') ?: NULL,
      conditions: $this->getContextValue('conditions') ?: NULL,
      sortField: $this->getContextValue('sort_field') ?: NULL,
      sortDirection: (string) ($this->getContextValue('sort_direction') ?: 'asc'),
      limit: (int) ($this->getContextValue('limit') ?: 100),
      offset: (int) ($this->getContextValue('offset') ?: 0),
      expressions: $this->getContextValue('expressions') ?: NULL,
      groupings: $this->getContextValue('groupings') ?: NULL,
    );
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
