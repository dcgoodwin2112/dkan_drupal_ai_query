<?php

namespace Drupal\dkan_ai_query\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for DKAN AI Query Conversation entities.
 */
class ConversationAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer dkan ai query conversations')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $isOwner = $entity->get('uid')->target_id == $account->id();

    return match ($operation) {
      'view', 'update', 'delete' => AccessResult::allowedIf(
        $isOwner && $account->hasPermission('manage own dkan ai query conversations')
      )->cachePerPermissions()->cachePerUser(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'manage own dkan ai query conversations');
  }

}
