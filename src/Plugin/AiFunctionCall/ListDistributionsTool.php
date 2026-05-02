<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_drupal_ai_query\Service\DatasetCaveatRegistry;
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
  description: 'Get distributions (data files) for a dataset. Accepts a dataset UUID, resource_id (identifier__version), or dataset title for fuzzy lookup. Returns the resource_id needed for datastore tools, plus any dataset-level caveats.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'dataset_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Dataset reference'),
      description: new TranslatableMarkup('Dataset UUID, resource_id (identifier__version), or dataset title for fuzzy lookup.'),
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
   * The resource id resolver.
   *
   * @var \Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver
   */
  protected ResourceIdResolver $resolver;

  /**
   * The dataset caveat registry.
   *
   * @var \Drupal\dkan_drupal_ai_query\Service\DatasetCaveatRegistry
   */
  protected DatasetCaveatRegistry $caveats;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->metastoreTools = $container->get('dkan_query_tools.metastore');
    $instance->resolver = $container->get('dkan_drupal_ai_query.resource_id_resolver');
    $instance->caveats = $container->get('dkan_drupal_ai_query.dataset_caveat_registry');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $input = ResourceIdResolver::normalize((string) $this->getContextValue('dataset_id'));
    if ($input === '') {
      $this->setOutput(json_encode([
        'error' => 'dataset reference is required. Pass a dataset UUID, resource_id, or title.',
      ], JSON_UNESCAPED_SLASHES));
      return;
    }
    $datasetUuid = $this->resolver->resolveToDatasetUuid($input);
    if ($datasetUuid === NULL) {
      $this->setOutput(json_encode([
        'error' => "Could not resolve dataset: {$input}",
      ], JSON_UNESCAPED_SLASHES));
      return;
    }
    $result = $this->metastoreTools->listDistributions($datasetUuid);
    // Surface dataset-level caveats so callers reaching the resource_id via
    // this tool see the same compliance / coverage warnings that
    // find_dataset_resources returns. Without this, the prompt's "honor
    // caveats" rule has a silent gap when the agent goes UUID → datastore
    // without ever calling find_dataset_resources.
    $result = $this->caveats->attach($result, $datasetUuid);
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
