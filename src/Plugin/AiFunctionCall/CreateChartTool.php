<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Render a Vega-Lite chart from query results.
 *
 * The actual capture of the spec is done by ArtifactCaptureSubscriber on the
 * AgentToolFinishedExecutionEvent. This tool returns a small status payload
 * to keep the LLM-visible result compact.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:create_chart',
  function_name: 'create_chart',
  name: 'Create chart',
  description: 'Render an interactive chart from query results. Pass a Vega-Lite v5 specification with data.values containing the rows. Use after query_datastore when visualization would help. Good for: comparisons (bar), trends (line), distributions (histogram), proportions (arc), correlations (point). The spec must include the $schema field.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'spec' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Vega-Lite spec'),
      description: new TranslatableMarkup('Vega-Lite v5 spec as a JSON string. Include $schema, data.values, mark, and encoding. Example: {"$schema":"https://vega.github.io/schema/vega-lite/v5.json","data":{"values":[{"x":"A","y":10}]},"mark":"bar","encoding":{"x":{"field":"x","type":"nominal"},"y":{"field":"y","type":"quantitative"}}}.'),
      required: TRUE,
    ),
  ],
)]
class CreateChartTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // The subscriber pulls the spec out of the tool's context after this
    // returns. Stub the LLM-visible output so the conversation stays small.
    $this->setOutput(json_encode(['status' => 'chart_rendered'], JSON_UNESCAPED_SLASHES));
  }

}
