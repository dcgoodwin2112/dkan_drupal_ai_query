<?php

namespace Drupal\ai_agents\Event;

/**
 * Stub for AgentToolPreExecuteEvent.
 *
 * Mirrors AgentToolFinishedExecutionEvent's shape — the subscriber only
 * reads thread id, runner id, and the tool's function name from each
 * event, so the same minimal surface works for both.
 */
class AgentToolPreExecuteEvent {

  public const EVENT_NAME = 'ai_agents.tool_pre_executed';

  public function __construct(
    protected string $threadId = '',
    protected mixed $tool = NULL,
    protected string $runnerId = '',
  ) {}

  public function getThreadId(): string {
    return $this->threadId;
  }

  public function getAgentRunnerId(): string {
    return $this->runnerId !== '' ? $this->runnerId : $this->threadId;
  }

  public function getTool(): mixed {
    return $this->tool;
  }

}
