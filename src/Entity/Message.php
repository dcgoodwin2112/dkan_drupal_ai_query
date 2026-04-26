<?php

namespace Drupal\dkan_drupal_ai_query\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * DKAN AI Query Message entity.
 *
 * @ContentEntityType(
 *   id = "dkan_aiq_message",
 *   label = @Translation("DKAN AI Query Message"),
 *   base_table = "dkan_aiq_messages",
 *   entity_keys = {
 *     "id" = "id",
 *   },
 *   internal = TRUE,
 * )
 */
class Message extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['conversation_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Conversation'))
      ->setSetting('target_type', 'dkan_aiq_conversation')
      ->setRequired(TRUE)
      ->setTranslatable(FALSE);

    $fields['role'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Role'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings(['max_length' => 16]);

    $fields['content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Content'))
      ->setTranslatable(FALSE);

    $fields['artifacts'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Artifacts JSON'))
      ->setDescription(t('JSON-encoded artifacts (chart specs, table data) captured during this turn.'))
      ->setTranslatable(FALSE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDefaultValue(0)
      ->setTranslatable(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setTranslatable(FALSE);

    return $fields;
  }

}
