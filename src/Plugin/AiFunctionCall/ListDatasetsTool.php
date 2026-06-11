<?php

namespace Drupal\dkan_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List datasets with pagination.
 */
#[FunctionCall(
  id: 'dkan_ai_query:list_datasets',
  function_name: 'list_datasets',
  name: 'List datasets',
  description: 'List all datasets with pagination. Returns title, identifier, description, distribution count.',
  group: 'dkan_ai_query',
  context_definitions: [
    'offset' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Offset'),
      description: new TranslatableMarkup('Number of datasets to skip (default 0).'),
      required: FALSE,
    ),
    'limit' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Max datasets to return (1-100, default 25).'),
      required: FALSE,
    ),
  ],
)]
class ListDatasetsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The metastore tools.
   *
   * @var \Drupal\dkan_query_tools\Tool\MetastoreTools
   */
  protected MetastoreTools $metastoreTools;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->metastoreTools = $container->get('dkan_query_tools.metastore');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $result = $this->metastoreTools->listDatasets(
      offset: (int) ($this->getContextValue('offset') ?: 0),
      limit: (int) ($this->getContextValue('limit') ?: 25),
    );
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
