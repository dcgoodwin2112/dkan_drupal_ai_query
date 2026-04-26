<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Get distributions (data files) for a dataset.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:list_distributions',
  function_name: 'list_distributions',
  name: 'List distributions',
  description: 'Get distributions (data files) for a dataset by UUID. Returns resource_id needed for datastore tools.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'dataset_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Dataset ID'),
      description: new TranslatableMarkup('Dataset UUID.'),
      required: TRUE,
    ),
  ],
)]
class ListDistributionsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

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
    $result = $this->metastoreTools->listDistributions(
      datasetId: ResourceIdResolver::normalize((string) $this->getContextValue('dataset_id')),
    );
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
