<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Service;

/**
 * Per-process in-memory store of tool calls executed during an eval run.
 *
 * Records one entry per tool execution keyed by thread id. Designed for the
 * Drush eval harness; stays out of the runtime path used by the live
 * controller. Not safe across requests — eval is single-process by design.
 *
 * Companion: ToolCallEvalCollectorSubscriber writes; EvalRunner reads then
 * forgets per case.
 */
class EvalToolCallCollector {

  /**
   * Per-thread list of recorded tool-call entries.
   *
   * @var array<string, list<array{iteration:int,tool:string,input:array,output_bytes:int}>>
   */
  protected array $byThread = [];

  /**
   * Record one tool execution.
   */
  public function record(string $threadId, string $toolName, array $input, int $outputBytes): void {
    if (!isset($this->byThread[$threadId])) {
      $this->byThread[$threadId] = [];
    }
    $iteration = count($this->byThread[$threadId]) + 1;
    $this->byThread[$threadId][] = [
      'iteration' => $iteration,
      'tool' => $toolName,
      'input' => $this->summarizeInput($input),
      'output_bytes' => $outputBytes,
    ];
  }

  /**
   * Read recorded calls for a thread, in execution order.
   *
   * @return array
   *   List of call entries, each with iteration, tool, input, output_bytes.
   */
  public function load(string $threadId): array {
    return $this->byThread[$threadId] ?? [];
  }

  /**
   * Drop a thread's recorded calls. Call after copying out the result.
   */
  public function forget(string $threadId): void {
    unset($this->byThread[$threadId]);
  }

  /**
   * Trim verbose values so input summaries don't bloat the JSONL.
   *
   * Strings beyond 200 chars are truncated; non-scalar values are replaced
   * with their type name so the JSONL stays grep-friendly without losing
   * the shape of the call.
   */
  protected function summarizeInput(array $input): array {
    $summary = [];
    foreach ($input as $name => $value) {
      if (is_string($value)) {
        $summary[$name] = mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
      }
      elseif (is_scalar($value) || $value === NULL) {
        $summary[$name] = $value;
      }
      else {
        $summary[$name] = '<' . get_debug_type($value) . '>';
      }
    }
    return $summary;
  }

}
