<?php

declare(strict_types=1);

namespace Opis\JsonSchema;

/**
 * Stub for Opis\JsonSchema\ValidationResult used in DatastoreRawQueryRunnerTest.
 *
 * Mirrors only the addError/getErrors surface the runner walks when formatting
 * a ValidationException's payload into JSON-serializable output.
 */
final class ValidationResult {

  /** @var ValidationError[] */
  protected array $errors = [];

  protected int $totalErrors = 0;

  public function __construct(int $maxErrors = 1) {
    // No-op; preserved for parity with the real signature.
  }

  public function addError(ValidationError $error): self {
    $this->errors[] = $error;
    $this->totalErrors += $error->subErrorsCount() + 1;
    return $this;
  }

  /** @return ValidationError[] */
  public function getErrors(): array {
    return $this->errors;
  }

  public function totalErrors(): int {
    return $this->totalErrors;
  }

}
