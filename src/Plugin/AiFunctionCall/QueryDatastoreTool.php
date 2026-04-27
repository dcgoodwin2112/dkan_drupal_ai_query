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
 * Query a DKAN datastore resource with filters, sorting, and aggregation.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:query_datastore',
  function_name: 'query_datastore',
  name: 'Query datastore',
  description: 'Query a DKAN datastore resource with filters, sorting, pagination, and aggregation. resource_id accepts {identifier}__{version} OR a dataset title for fuzzy lookup. Conditions must be a JSON string array. Operators: =, <>, <, <=, >, >=, like, contains, starts with, in, not in, between. All data is stored as text — string comparisons apply ("9" > "10" alphabetically); use aggregate expressions for true numeric ordering. Maximum 500 rows per call.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Resource ID'),
      description: new TranslatableMarkup('In the form identifier__version, or a dataset title for fuzzy lookup. Pass the bare value with no surrounding quotes. Examples: abc123__1773329007  or  Shark Tagging.'),
      required: TRUE,
    ),
    'columns' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Columns'),
      description: new TranslatableMarkup('Comma-separated column names to return. Omit for all.'),
      required: FALSE,
    ),
    'conditions' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Conditions'),
      description: new TranslatableMarkup('JSON array of conditions, e.g. [{"property":"state","value":"CA","operator":"="}]. For IN: [{"property":"state","value":["CA","TX"],"operator":"in"}]. For OR groups: [{"groupOperator":"or","conditions":[...]}].'),
      required: FALSE,
    ),
    'sort_field' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Sort field'),
      description: new TranslatableMarkup('Column to sort by.'),
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
      description: new TranslatableMarkup('JSON array of aggregate expressions, e.g. [{"operator":"sum","operands":["revenue"],"alias":"total"}]. Operators: sum, count, avg, max, min. Must use with groupings.'),
      required: FALSE,
    ),
    'groupings' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Groupings'),
      description: new TranslatableMarkup('Comma-separated GROUP BY columns. Required with aggregate expressions.'),
      required: FALSE,
    ),
  ],
)]
class QueryDatastoreTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The datastore tools service from dkan_query_tools.
   *
   * @var \Drupal\dkan_query_tools\Tool\DatastoreTools
   */
  protected DatastoreTools $datastoreTools;

  /**
   * The resource id resolver service.
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
      $this->setOutput(json_encode([
        'error' => "Could not resolve resource: {$resourceIdInput}",
      ], JSON_UNESCAPED_SLASHES));
      return;
    }

    $sortDirection = (string) ($this->getContextValue('sort_direction') ?: 'asc');
    $limit = (int) ($this->getContextValue('limit') ?: 100);
    $offset = (int) ($this->getContextValue('offset') ?: 0);

    $result = $this->datastoreTools->queryDatastore(
      resourceId: $resolved,
      columns: $this->getContextValue('columns') ?: NULL,
      conditions: $this->getContextValue('conditions') ?: NULL,
      sortField: $this->getContextValue('sort_field') ?: NULL,
      sortDirection: $sortDirection,
      limit: $limit,
      offset: $offset,
      expressions: $this->getContextValue('expressions') ?: NULL,
      groupings: $this->getContextValue('groupings') ?: NULL,
    );

    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
