<?php

namespace Drupal\Core\Entity;

/**
 * Minimal stub for Drupal\Core\Entity\EntityTypeManagerInterface.
 */
interface EntityTypeManagerInterface {

  public function getStorage(string $entity_type_id): EntityStorageInterface;

}

/**
 * Minimal stub for Drupal\Core\Entity\EntityStorageInterface.
 */
interface EntityStorageInterface {

  public function load(string $id): ?object;

  public function loadMultiple(?array $ids = NULL): array;

}
