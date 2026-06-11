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

  /**
   * The validation result carried by the exception.
   *
   * @var \Opis\JsonSchema\ValidationResult
   */
  private ValidationResult $validationResult;

  public function __construct(string $message, ValidationResult $validationResult) {
    parent::__construct($message);
    $this->validationResult = $validationResult;
  }

  /**
   * Returns the validation result.
   */
  public function getResult(): ValidationResult {
    return $this->validationResult;
  }

}
