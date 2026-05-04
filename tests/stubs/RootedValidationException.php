<?php

declare(strict_types=1);

namespace RootedData\Exception;

use Opis\JsonSchema\ValidationResult;

/**
 * Stub for RootedData\Exception\ValidationException.
 *
 * Used by DatastoreRawQueryRunnerTest to exercise the validation-error
 * formatting branch without booting the real RootedJsonData / Opis stack.
 */
class ValidationException extends \InvalidArgumentException {

  private ValidationResult $validationResult;

  public function __construct(string $message, ValidationResult $validationResult) {
    parent::__construct($message);
    $this->validationResult = $validationResult;
  }

  public function getResult(): ValidationResult {
    return $this->validationResult;
  }

}
