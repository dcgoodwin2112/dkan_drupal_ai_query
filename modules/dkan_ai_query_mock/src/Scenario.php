<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query_mock;

/**
 * Parsed mock scenario: a scripted sequence of LLM turns.
 *
 * Each turn is either:
 *   - tool_calls: emits one or more `ToolsFunctionOutput` tool requests,
 *   - final_answer: emits an assistant text message and ends the agent loop.
 */
final class Scenario {

  /**
   * Constructs the value object.
   *
   * @param string $id
   *   Scenario id (also the YAML stem).
   * @param string $description
   *   Operator-facing description.
   * @param array $match
   *   Auto-match rules. `question_contains` (string[]) requires every listed
   *   substring to appear (case-insensitively) in the latest user message.
   * @param array $turns
   *   Ordered turns. Last turn should be `final_answer`.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $description,
    public readonly array $match,
    public readonly array $turns,
  ) {}

  /**
   * Returns the turn at the given index, or a synthetic terminal turn.
   *
   * If a scenario runs out of scripted turns (e.g. the agent kept asking past
   * the script), we emit a final_answer so the agent loop exits cleanly rather
   * than spinning to max_loops.
   */
  public function turnAt(int $index): array {
    if ($index < count($this->turns)) {
      return $this->turns[$index];
    }
    return [
      'type' => 'final_answer',
      'content' => sprintf(
        '_(scenario "%s" ran out of scripted turns at index %d — emitting synthetic final_answer to end the loop.)_',
        $this->id,
        $index,
      ),
    ];
  }

}
