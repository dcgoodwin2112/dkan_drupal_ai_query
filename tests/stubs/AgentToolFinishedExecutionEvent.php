<?php

namespace Drupal\ai_agents\Event;

/**
 * Stub for AgentToolFinishedExecutionEvent.
 *
 * Mirrors the upstream contract well enough for unit testing
 * ArtifactCaptureSubscriber without a full Drupal bootstrap.
 */
class AgentToolFinishedExecutionEvent {

  public const EVENT_NAME = 'ai_agents.tool_finished_execution';

  public function __construct(
    protected string $threadId = '',
    protected mixed $tool = NULL,
    protected string $runnerId = '',
  ) {}

  /**
   * Return the agent thread id (PrivateTempStore key in real runs).
   */
  public function getThreadId(): string {
    return $this->threadId;
  }

  /**
   * Return the runner id, falling back to thread id when unset.
   */
  public function getAgentRunnerId(): string {
    return $this->runnerId !== '' ? $this->runnerId : $this->threadId;
  }

  /**
   * Return the FunctionCall plugin (or stub) that produced the result.
   */
  public function getTool(): mixed {
    return $this->tool;
  }

}
