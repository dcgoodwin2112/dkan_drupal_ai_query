<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search column names/descriptions across all imported datastore resources.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:search_columns',
  function_name: 'search_columns',
  name: 'Search columns',
  description: 'Search column names and descriptions across ALL imported datastore resources. Use to find which datasets contain specific data (e.g. "state", "price", "smoking").',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'search_term' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search term'),
      description: new TranslatableMarkup('Column name or description substring (case-insensitive).'),
      required: TRUE,
    ),
    'search_in' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Search in'),
      description: new TranslatableMarkup('Where to search: name, description, or both. Default: name.'),
      required: FALSE,
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Max matches (default 100).'),
      required: FALSE,
    ),
  ],
)]
class SearchColumnsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The datastore tools.
   *
   * @var \Drupal\dkan_query_tools\Tool\DatastoreTools
   */
  protected DatastoreTools $datastoreTools;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->datastoreTools = $container->get('dkan_query_tools.datastore');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $result = $this->datastoreTools->searchColumns(
      searchTerm: (string) $this->getContextValue('search_term'),
      searchIn: (string) ($this->getContextValue('search_in') ?: 'name'),
      limit: (int) ($this->getContextValue('limit') ?: 100),
    );
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
