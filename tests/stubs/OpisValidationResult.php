<?php

declare(strict_types=1);

namespace Opis\JsonSchema;

/**
 * Stub for Opis\JsonSchema\ValidationResult.
 *
 * Used in DatastoreRawQueryRunnerTest. Mirrors only the addError/getErrors
 * surface the runner walks when formatting a ValidationException's payload
 * into JSON-serializable output.
 */
final class ValidationResult {

  /**
   * Collected validation errors.
   *
   * @var ValidationError[]
   */
  protected array $errors = [];

  /**
   * Total error count including sub-errors.
   *
   * @var int
   */
  protected int $totalErrors = 0;

  public function __construct(int $maxErrors = 1) {
    // No-op; preserved for parity with the real signature.
  }

  /**
   * Adds an error and updates the running total.
   */
  public function addError(ValidationError $error): self {
    $this->errors[] = $error;
    $this->totalErrors += $error->subErrorsCount() + 1;
    return $this;
  }

  /**
   * Returns the collected errors.
   *
   * @return ValidationError[]
   *   The validation errors added so far.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Returns the total error count.
   */
  public function totalErrors(): int {
    return $this->totalErrors;
  }

}
