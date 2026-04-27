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
  ) {}

  public function getThreadId(): string {
    return $this->threadId;
  }

  public function getTool(): mixed {
    return $this->tool;
  }

}
