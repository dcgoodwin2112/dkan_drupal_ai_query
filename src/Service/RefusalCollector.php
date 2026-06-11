<?php

namespace Drupal\dkan_ai_query\Service;

/**
 * Per-process registry of structured refusals captured during agent solves.
 *
 * RefuseTool produces a structured JSON payload as its tool result. The
 * AgentToolFinishedExecutionEvent fires synchronously inside `solve()`,
 * so we record the payload here keyed by `thread_id` (a.k.a. runner id).
 * Both the CLI eval runner and the HTTP controller read from this same
 * service after the solve returns. In-memory because:
 *  - Eval (Drush) has no session for PrivateTempStore.
 *  - The controller's request lives within the same PHP process as the
 *    solve, so no cross-process persistence is needed.
 *
 * Distinct from ArtifactStorage, which is session-backed and UI-facing.
 */
class RefusalCollector {

  /**
   * Map of thread_id => last refusal payload recorded for that thread.
   *
   * @var array<string, array>
   */
  protected array $refusals = [];

  /**
   * Record a structured refusal for a thread.
   *
   * If the agent calls `refuse` more than once in a single solve (it should
   * not), the latest payload wins. Eval scoring uses the final state.
   */
  public function record(string $threadId, array $payload): void {
    if ($threadId === '') {
      return;
    }
    $this->refusals[$threadId] = $payload;
  }

  /**
   * Return the recorded refusal payload for a thread, or NULL.
   */
  public function get(string $threadId): ?array {
    return $this->refusals[$threadId] ?? NULL;
  }

  /**
   * Drop the recorded refusal (called between eval cases).
   */
  public function forget(string $threadId): void {
    unset($this->refusals[$threadId]);
  }

}
