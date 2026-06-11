<?php

namespace Drupal\dkan_ai_query\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * DKAN AI Query Conversation entity.
 *
 * @ContentEntityType(
 *   id = "dkan_aiq_conversation",
 *   label = @Translation("DKAN AI Query Conversation"),
 *   label_collection = @Translation("DKAN AI Query Conversations"),
 *   label_singular = @Translation("conversation"),
 *   label_plural = @Translation("conversations"),
 *   handlers = {
 *     "access" = "Drupal\dkan_ai_query\Entity\ConversationAccessControlHandler",
 *   },
 *   base_table = "dkan_aiq_conversations",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *     "uid" = "uid",
 *   },
 *   internal = TRUE,
 * )
 */
class Conversation extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setTranslatable(FALSE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings(['max_length' => 255]);

    $fields['dataset_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Dataset ID'))
      ->setTranslatable(FALSE)
      ->setSettings(['max_length' => 255]);

    $fields['pinned'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Pinned'))
      ->setDefaultValue(FALSE)
      ->setTranslatable(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setTranslatable(FALSE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setTranslatable(FALSE);

    return $fields;
  }

}
