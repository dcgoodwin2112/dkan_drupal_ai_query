<?php

declare(strict_types=1);

namespace Opis\JsonSchema;

/**
 * Stub for Opis\JsonSchema\ValidationError used in DatastoreRawQueryRunnerTest.
 *
 * Mirrors only the surface DatastoreRawQueryRunner reads when formatting an
 * Opis\JsonSchema\ValidationResult into the {pointer, keyword, args} array
 * returned to the LLM as `validation_errors`.
 */
final class ValidationError {

  /**
   * Full data pointer (parent pointer merged with the local one).
   *
   * @var array
   */
  protected array $dataPointer;

  public function __construct(
    protected mixed $data,
    array $dataPointer,
    protected array $parentDataPointer,
    protected mixed $schema,
    protected string $keyword,
    protected array $keywordArgs = [],
    protected array $subErrors = [],
  ) {
    $this->dataPointer = $parentDataPointer ? array_merge($parentDataPointer, $dataPointer) : $dataPointer;
  }

  /**
   * Returns the full data pointer.
   */
  public function dataPointer(): array {
    return $this->dataPointer;
  }

  /**
   * Returns the failing schema keyword.
   */
  public function keyword(): string {
    return $this->keyword;
  }

  /**
   * Returns the keyword arguments.
   */
  public function keywordArgs(): array {
    return $this->keywordArgs;
  }

  /**
   * Returns the number of sub-errors.
   */
  public function subErrorsCount(): int {
    return count($this->subErrors);
  }

}
