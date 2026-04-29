<?php

declare(strict_types=1);

namespace Drupal\dkan_drupal_ai_query\Eval;

/**
 * Single golden case loaded from YAML.
 */
final class GoldenCase {

  public function __construct(
    public readonly string $id,
    public readonly string $question,
    public readonly ?string $expectedDatasetId,
    public readonly array $expectedColumnsUsed,
    public readonly ?string $expectedAnswerPattern,
    public readonly bool $expectedRefusal,
    public readonly ?string $expectedFailureCategory,
    public readonly string $notes,
  ) {}

  /**
   * Build a GoldenCase from one YAML row, validating required fields.
   */
  public static function fromArray(array $row): self {
    foreach (['id', 'question'] as $required) {
      if (!isset($row[$required]) || !is_string($row[$required]) || $row[$required] === '') {
        throw new \InvalidArgumentException("Golden case missing required string field: {$required}");
      }
    }
    return new self(
      id: $row['id'],
      question: $row['question'],
      expectedDatasetId: isset($row['expected_dataset_id']) ? (string) $row['expected_dataset_id'] : NULL,
      expectedColumnsUsed: array_values(array_map('strval', $row['expected_columns_used'] ?? [])),
      expectedAnswerPattern: isset($row['expected_answer_pattern']) ? (string) $row['expected_answer_pattern'] : NULL,
      expectedRefusal: (bool) ($row['expected_refusal'] ?? FALSE),
      expectedFailureCategory: isset($row['expected_failure_category']) ? (string) $row['expected_failure_category'] : NULL,
      notes: (string) ($row['notes'] ?? ''),
    );
  }

}
