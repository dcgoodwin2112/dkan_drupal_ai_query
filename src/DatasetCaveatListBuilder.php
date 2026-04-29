<?php

namespace Drupal\dkan_drupal_ai_query;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Tabular admin listing of dataset caveat entities.
 */
class DatasetCaveatListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Dataset'),
      'uuid' => $this->t('Dataset UUID'),
      'has_suppression' => $this->t('Suppression'),
      'column_caveats' => $this->t('Column caveats'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\dkan_drupal_ai_query\DatasetCaveatInterface $entity */
    return [
      'label' => $entity->label(),
      'uuid' => $entity->getDatasetUuid(),
      'has_suppression' => $entity->getSuppression() ? $this->t('Yes') : $this->t('—'),
      'column_caveats' => count($entity->getColumnCaveats()),
    ] + parent::buildRow($entity);
  }

}
