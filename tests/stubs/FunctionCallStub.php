<?php

/**
 * @file
 * Tool stub used by ArtifactCaptureSubscriberTest.
 *
 * Lives outside any namespace so the test file can `use FunctionCallStub` and
 * not collide with Drupal AI's real FunctionCallInterface.
 */

class FunctionCallStub {

  public function __construct(
    protected string $functionName,
    protected string $output,
    protected array $context = [],
  ) {}

  public function getFunctionName(): string {
    return $this->functionName;
  }

  public function getReadableOutput(): string {
    return $this->output;
  }

  public function setOutput(string $output): void {
    $this->output = $output;
  }

  public function getContextValue(string $name): mixed {
    if (!array_key_exists($name, $this->context)) {
      throw new \RuntimeException("Context '$name' not set.");
    }
    return $this->context[$name];
  }

  public function getContextValues(): array {
    return $this->context;
  }

}
