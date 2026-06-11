<?php

namespace Drupal\dkan_ai_query\Service;

/**
 * Per-process per-thread counter of unknown_column tool errors.
 *
 * Mirrors RefusalCollector's shape and rationale: in-memory because the agent
 * loop runs in a single PHP process per request, and CLI eval has no session.
 * Used by UnknownColumnGuardSubscriber to short-circuit the loop after three
 * consecutive unknown_column errors rather than burning every remaining
 * iteration on the same kind of mistake.
 */
class UnknownColumnCounter {

  protected const TRIP_THRESHOLD = 3;

  /**
   * Map of thread_id => running unknown_column count for this solve.
   *
   * @var array<string, int>
   */
  protected array $counts = [];

  /**
   * Increment the counter for a thread and return the new value.
   */
  public function bump(string $threadId): int {
    if ($threadId === '') {
      return 0;
    }
    $this->counts[$threadId] = ($this->counts[$threadId] ?? 0) + 1;
    return $this->counts[$threadId];
  }

  /**
   * Read the current count for a thread without bumping it.
   */
  public function count(string $threadId): int {
    return $this->counts[$threadId] ?? 0;
  }

  /**
   * Drop the counter for a thread (called between eval cases / turns).
   */
  public function forget(string $threadId): void {
    unset($this->counts[$threadId]);
  }

  /**
   * Threshold at which the guard subscriber injects a refusal.
   */
  public static function tripThreshold(): int {
    return self::TRIP_THRESHOLD;
  }

}
