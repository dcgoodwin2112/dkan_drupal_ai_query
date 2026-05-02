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
 * Get the data dictionary linked to a dataset or distribution.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:get_data_dictionary',
  function_name: 'get_data_dictionary',
  name: 'Get data dictionary',
  description: 'Get the standalone data dictionary linked to a dataset or distribution. In most cases you do NOT need this — get_datastore_schema already merges per-column dictionary fields (dictionary_title, dictionary_description, dictionary_type). Call this only when you need the dictionary URL or the dictionary fields list independent of any single resource.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'resource_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Resource or dataset ID'),
      description: new TranslatableMarkup('Dataset UUID, resource_id (identifier__version), or a dataset title for fuzzy lookup.'),
      required: TRUE,
    ),
  ],
)]
class GetDataDictionaryTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The metastore tools.
   */
  protected MetastoreTools $metastoreTools;

  /**
   * The resource id resolver.
   */
  protected ResourceIdResolver $resolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->metastoreTools = $container->get('dkan_query_tools.metastore');
    $instance->resolver = $container->get('dkan_drupal_ai_query.resource_id_resolver');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $input = ResourceIdResolver::normalize((string) $this->getContextValue('resource_id'));
    // Try dataset UUID first (no resolver needed); fall back to resource
    // resolution for titles or non-canonical resource IDs.
    $lookup = $input;
    if (str_contains($input, '__')) {
      $resolved = $this->resolver->resolve($input);
      $lookup = $resolved ?? $input;
    }
    elseif (!$this->looksLikeUuid($input)) {
      $resolved = $this->resolver->resolve($input);
      if ($resolved !== NULL) {
        $lookup = $resolved;
      }
    }
    $result = $this->metastoreTools->getDataDictionary($lookup);
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

  /**
   * Cheap UUID-shape check (8-4-4-4-12 hex).
   */
  protected function looksLikeUuid(string $value): bool {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
  }

}
