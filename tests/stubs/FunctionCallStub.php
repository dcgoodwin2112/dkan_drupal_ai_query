<?php

/**
 * @file
 * Tool stub used by ArtifactCaptureSubscriberTest.
 *
 * Lives outside any namespace so the test file can `use FunctionCallStub` and
 * not collide with Drupal AI's real FunctionCallInterface.
 */

/**
 * Minimal stand-in for ai_agents' FunctionCall plugin used in unit tests.
 *
 * Exposes only the surface that ArtifactCaptureSubscriber and friends call:
 * function name, readable output, and context values. Lives outside any
 * namespace so the test file can `use FunctionCallStub` without colliding
 * with Drupal AI's real FunctionCallInterface.
 */
class FunctionCallStub {

  public function __construct(
    protected string $functionName,
    protected string $output,
    protected array $context = [],
  ) {}

  /**
   * Return the function name, mirroring the real plugin's accessor.
   */
  public function getFunctionName(): string {
    return $this->functionName;
  }

  /**
   * Return the LLM-visible string output.
   */
  public function getReadableOutput(): string {
    return $this->output;
  }

  /**
   * Replace the output (used when chaining a follow-up state in a test).
   */
  public function setOutput(string $output): void {
    $this->output = $output;
  }

  /**
   * Return one context value or throw when unset.
   */
  public function getContextValue(string $name): mixed {
    if (!array_key_exists($name, $this->context)) {
      throw new \RuntimeException("Context '$name' not set.");
    }
    return $this->context[$name];
  }

  /**
   * Return the context array as-is.
   */
  public function getContextValues(): array {
    return $this->context;
  }

}
