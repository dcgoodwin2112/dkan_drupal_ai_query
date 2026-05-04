<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_drupal_ai_query\Service\DatastoreRawQueryRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Run a DKAN DatastoreQuery payload verbatim — agent escape hatch.
 *
 * Most queries should go through `query_datastore` / `query_datastore_join`,
 * which provide flat-string params, sanity_flags, and dictionary enrichment.
 * Use this tool only when the flat tools cannot express the query: nested
 * groupOperator, three-resource joins, compound expressions, selective
 * `{count, results, schema, keys}` payloads. The response shape matches
 * DKAN's REST endpoint verbatim and does not include `sanity_flags`.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:query_datastore_raw',
  function_name: 'query_datastore_raw',
  name: 'Query datastore (raw)',
  description: 'Advanced escape hatch. Pass a JSON DatastoreQuery payload (DKAN DSL: resources, properties, conditions, joins, groupings, sorts, limit, offset, count, results, schema, keys). Use ONLY for shapes the flat query_datastore tools cannot express — nested groupOperator OR, three-way joins, compound expressions. Response is the raw REST shape; no sanity_flags. Single resource example: {"resources":[{"id":"<resource_id>","alias":"t"}],"conditions":[{"property":"state","value":"CA","operator":"="}],"limit":10}.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'payload' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Payload'),
      description: new TranslatableMarkup('JSON-encoded DatastoreQuery object. resources[].id may be a {identifier}__{version} resource_id or a fuzzy dataset title. Validation errors are returned as {error, validation_errors}.'),
      required: TRUE,
    ),
  ],
)]
class QueryDatastoreRawTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The raw query runner service.
   *
   * @var \Drupal\dkan_drupal_ai_query\Service\DatastoreRawQueryRunner
   */
  protected DatastoreRawQueryRunner $runner;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->runner = $container->get('dkan_drupal_ai_query.raw_query_runner');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $payload = (string) $this->getContextValue('payload');
    $result = $this->runner->run($payload);
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
