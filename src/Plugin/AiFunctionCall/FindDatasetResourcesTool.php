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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Find a dataset by partial title and return its resource_ids.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:find_dataset_resources',
  function_name: 'find_dataset_resources',
  name: 'Find dataset resources',
  description: 'Find a dataset by title and get its resource_ids. Use this instead of list_distributions when you know the dataset title — avoids needing to type the UUID. Returns dataset_id, title, and distributions with resource_ids.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Title'),
      description: new TranslatableMarkup('Dataset title or partial title (case-insensitive).'),
      required: TRUE,
    ),
  ],
)]
class FindDatasetResourcesTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The resource id resolver.
   */
  protected ResourceIdResolver $resolver;

  /**
   * The dataset caveat registry.
   */
  protected DatasetCaveatRegistry $caveats;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->resolver = $container->get('dkan_drupal_ai_query.resource_id_resolver');
    $instance->caveats = $container->get('dkan_drupal_ai_query.dataset_caveat_registry');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $result = $this->resolver->findDatasetResources((string) $this->getContextValue('title'));
    if (isset($result['dataset_id'])) {
      $result = $this->caveats->attach($result, $result['dataset_id']);
    }
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
