<?php

namespace Drupal\dkan_ai_query;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for the DatasetCaveat config entity.
 */
interface DatasetCaveatInterface extends ConfigEntityInterface {

  /**
   * Return the dataset UUID this caveat record applies to.
   */
  public function getDatasetUuid(): string;

  /**
   * Return the suppression note, or NULL when unset.
   */
  public function getSuppression(): ?string;

  /**
   * Return the column_caveats map (column name => text).
   *
   * @return array<string, string>
   *   Map of column name to caveat text.
   */
  public function getColumnCaveats(): array;

  /**
   * Return the freshness sub-record.
   *
   * @return array{updated?: string, coverage?: string}
   *   Freshness keys: updated (ISO date) and coverage (plain-English range).
   */
  public function getFreshness(): array;

  /**
   * Return the code_lists map (column name => list of strings).
   *
   * @return array<string, array<int, string>>
   *   Map of column name to list of expected values.
   */
  public function getCodeLists(): array;

  /**
   * Project this entity to the array shape DatasetCaveatRegistry returns.
   *
   * Keys with empty values are omitted so callers receive the same shape
   * the legacy YAML-backed registry produced.
   */
  public function toCaveatArray(): array;

}
