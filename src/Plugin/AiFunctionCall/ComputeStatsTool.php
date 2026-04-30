<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dkan_drupal_ai_query\Service\StatsCalculator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Compute statistics that the datastore DSL cannot express.
 *
 * Pairs with query_datastore: SQL handles sum/count/avg/min/max on the full
 * table; this tool handles median/percentile/stddev/variance/quartiles/
 * correlation on rows the agent has already fetched.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:compute_stats',
  function_name: 'compute_stats',
  name: 'Compute statistics',
  description: 'Compute statistics SQL cannot express (median, percentile, stddev, variance, quartiles, correlation) on rows you have already fetched. Pass the rows from a prior query_datastore result inline. Do NOT use this for sum/count/avg/min/max — those go through query_datastore expressions, which run on the full table, not a 500-row sample.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'spec' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Stats spec'),
      description: new TranslatableMarkup('JSON: {"data":[{...row...},...],"operations":[{"type":"median","column":"x"},{"type":"percentile","column":"x","p":95},{"type":"correlation","columns":["x","y"]}]}. Operation types: median, percentile (requires p, 0<p<100), stddev, variance, quartiles (returns q1/q2/q3/iqr), correlation (requires columns:[a,b], Pearson).'),
      required: TRUE,
    ),
  ],
)]
class ComputeStatsTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The stats calculator.
   *
   * @var \Drupal\dkan_drupal_ai_query\Service\StatsCalculator
   */
  protected StatsCalculator $calculator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->calculator = $container->get('dkan_drupal_ai_query.stats_calculator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $raw = (string) $this->getContextValue('spec');
    $spec = json_decode($raw, TRUE);
    if (!is_array($spec) || !isset($spec['data']) || !is_array($spec['data']) || !isset($spec['operations']) || !is_array($spec['operations'])) {
      $this->setOutput(json_encode([
        'error' => 'invalid_spec',
        'message' => 'spec must be JSON of shape {"data":[{...},...],"operations":[{"type":"median","column":"x"},...]}.',
      ], JSON_UNESCAPED_SLASHES));
      return;
    }
    $result = $this->calculator->run($spec['data'], $spec['operations']);
    $this->setOutput(json_encode($result, JSON_UNESCAPED_SLASHES));
  }

}
