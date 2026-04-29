<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Terminate the loop with a structured refusal payload.
 *
 * The agent should call this — instead of free-text refusing in the final
 * answer — whenever it cannot answer:
 *  - no dataset matches the question (`reason_category: no_matching_dataset`)
 *  - the question is out of scope (`out_of_scope`, `write_request`)
 *  - data is out of coverage (`out_of_coverage`)
 *  - the DSL cannot express the query (`dsl_limitation`)
 *  - the agent has retried the same broken query too many times
 *    (`repeated_unknown_column`)
 *
 * The eval harness reads the structured payload directly via
 * AgentToolFinishedExecutionEvent — no regex over free-text refusals.
 * Downstream UI can render refusal cards distinctly from answers.
 */
#[FunctionCall(
  id: 'dkan_drupal_ai_query:refuse',
  function_name: 'refuse',
  name: 'Refuse',
  description: 'Terminate the conversation with a structured refusal. Use when the question cannot be answered: no matching dataset, out of scope, out of coverage, DSL limitation, or repeated tool errors. Always include reason_category and a short explanation.',
  group: 'dkan_drupal_ai_query',
  context_definitions: [
    'reason_category' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Reason category'),
      description: new TranslatableMarkup('One of: no_matching_dataset, out_of_scope, write_request, out_of_coverage, dsl_limitation, repeated_unknown_column, other.'),
      required: TRUE,
    ),
    'explanation' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Explanation'),
      description: new TranslatableMarkup('Short user-facing explanation. One or two sentences. Plain English, no apology boilerplate.'),
      required: TRUE,
    ),
    'datasets_searched' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Datasets searched'),
      description: new TranslatableMarkup('Optional comma-separated list of dataset titles or UUIDs the agent inspected before refusing.'),
      required: FALSE,
    ),
  ],
)]
class RefuseTool extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * Allowed reason_category values.
   *
   * Anything else is normalized to `other` so eval categorization stays
   * deterministic even if the model invents a label.
   */
  protected const ALLOWED_CATEGORIES = [
    'no_matching_dataset',
    'out_of_scope',
    'write_request',
    'out_of_coverage',
    'dsl_limitation',
    'repeated_unknown_column',
    'other',
  ];

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $category = (string) $this->getContextValue('reason_category');
    if (!in_array($category, self::ALLOWED_CATEGORIES, TRUE)) {
      $category = 'other';
    }
    $explanation = trim((string) $this->getContextValue('explanation'));
    $rawSearched = trim((string) ($this->getContextValue('datasets_searched') ?? ''));
    $searched = $rawSearched === ''
      ? []
      : array_values(array_filter(array_map('trim', explode(',', $rawSearched))));

    $payload = [
      'refused' => TRUE,
      'reason_category' => $category,
      'explanation' => $explanation,
      'datasets_searched' => $searched,
    ];
    $this->setOutput(json_encode($payload, JSON_UNESCAPED_SLASHES));
  }

}
