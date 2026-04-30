<?php

namespace Drupal\Core\Entity;

/**
 * Minimal stub for Drupal\Core\Entity\EntityTypeManagerInterface.
 */
interface EntityTypeManagerInterface {

  /**
   * Return the storage handler for the given entity type id.
   */
  public function getStorage(string $entity_type_id): EntityStorageInterface;

}

/**
 * Minimal stub for Drupal\Core\Entity\EntityStorageInterface.
 */
interface EntityStorageInterface {

  /**
   * Load a single entity by id.
   */
  public function load(string $id): ?object;

  /**
   * Load multiple entities by id, or all when $ids is NULL.
   */
  public function loadMultiple(?array $ids = NULL): array;

}
