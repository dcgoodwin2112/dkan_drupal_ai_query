<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_query_tools\Tool\SearchTools;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search datasets by keyword.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:search_datasets',
  function_name: 'search_datasets',
  name: 'Search datasets',
  description: 'Search datasets by keyword. Returns matching datasets with title, identifier, description, and distribution count.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'keyword' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Keyword'),
      description: new TranslatableMarkup('Search term.'),
      required: TRUE,
    ),
    'page' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('Page number, 1-based (default 1).'),
      required: FALSE,
    ),
    'page_size' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Page size'),
      description: new TranslatableMarkup('Results per page (default 10).'),
      required: FALSE,
    ),
  ],
)]
class SearchDatasetsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The search tools.
   *
   * @var \Drupal\dkan_query_tools\Tool\SearchTools
   */
  protected SearchTools $searchTools;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->searchTools = $container->get('dkan_query_tools.search');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $result = $this->searchTools->searchDatasets(
      keyword: (string) $this->getContextValue('keyword'),
      page: (int) ($this->getContextValue('page') ?: 1),
      pageSize: (int) ($this->getContextValue('page_size') ?: 10),
    );
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
