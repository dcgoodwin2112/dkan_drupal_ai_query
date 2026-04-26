<?php

namespace Drupal\dkan_drupal_ai_query\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * CRUD over the Conversation and Message entity types.
 *
 * Centralizes save / load / list / delete / toggle-pin so the controllers
 * stay thin and the agent runner has one place to record turns.
 */
class ConversationStorage {

  protected const CONVERSATION = 'dkan_aiq_conversation';
  protected const MESSAGE = 'dkan_aiq_message';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Create a new conversation owned by $uid with the given title.
   */
  public function createConversation(int $uid, string $title, ?string $datasetId = NULL): int {
    $entity = $this->entityTypeManager->getStorage(self::CONVERSATION)->create([
      'uid' => $uid,
      'title' => mb_substr($title ?: 'New conversation', 0, 255),
      'dataset_id' => $datasetId,
    ]);
    $entity->save();
    return (int) $entity->id();
  }

  /**
   * Append a message to a conversation.
   */
  public function addMessage(int $conversationId, string $role, string $content, array $artifacts = []): int {
    $existingCount = (int) $this->entityTypeManager->getStorage(self::MESSAGE)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation_id', $conversationId)
      ->count()
      ->execute();
    $entity = $this->entityTypeManager->getStorage(self::MESSAGE)->create([
      'conversation_id' => $conversationId,
      'role' => $role,
      'content' => $content,
      'artifacts' => $artifacts ? Json::encode($artifacts) : NULL,
      'weight' => $existingCount,
    ]);
    $entity->save();

    // Touch the conversation so its `changed` reflects the new turn.
    $conversation = $this->entityTypeManager->getStorage(self::CONVERSATION)->load($conversationId);
    if ($conversation) {
      $conversation->save();
    }

    return (int) $entity->id();
  }

  /**
   * Load all messages for a conversation, in weight order.
   *
   * Returns an array of associative arrays: role, content, artifacts.
   */
  public function getMessages(int $conversationId): array {
    $storage = $this->entityTypeManager->getStorage(self::MESSAGE);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation_id', $conversationId)
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    $out = [];
    foreach ($storage->loadMultiple($ids) as $msg) {
      $artifactsRaw = $msg->get('artifacts')->value;
      $out[] = [
        'role' => $msg->get('role')->value,
        'content' => $msg->get('content')->value,
        'artifacts' => $artifactsRaw ? (array) Json::decode($artifactsRaw) : [],
      ];
    }
    return $out;
  }

  /**
   * List conversations for a user, pinned first then most-recent.
   */
  public function listForUser(int $uid, int $limit = 50): array {
    $storage = $this->entityTypeManager->getStorage(self::CONVERSATION);
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->sort('pinned', 'DESC')
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();
    $out = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $out[] = [
        'id' => (int) $entity->id(),
        'title' => $entity->get('title')->value,
        'dataset_id' => $entity->get('dataset_id')->value,
        'pinned' => (bool) $entity->get('pinned')->value,
        'created' => (int) $entity->get('created')->value,
        'changed' => (int) $entity->get('changed')->value,
      ];
    }
    return $out;
  }

  /**
   * Delete a conversation and its messages.
   */
  public function deleteConversation(int $conversationId): void {
    $messageStorage = $this->entityTypeManager->getStorage(self::MESSAGE);
    $messageIds = $messageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation_id', $conversationId)
      ->execute();
    if ($messageIds) {
      $messageStorage->delete($messageStorage->loadMultiple($messageIds));
    }
    $conversation = $this->entityTypeManager->getStorage(self::CONVERSATION)->load($conversationId);
    if ($conversation) {
      $conversation->delete();
    }
  }

  /**
   * Load a conversation entity by id.
   */
  public function loadConversation(int $conversationId): mixed {
    return $this->entityTypeManager->getStorage(self::CONVERSATION)->load($conversationId);
  }

  /**
   * Flip the pinned flag on a conversation. Returns the new value.
   */
  public function togglePin(int $conversationId): bool {
    $conversation = $this->loadConversation($conversationId);
    if (!$conversation) {
      return FALSE;
    }
    $next = !((bool) $conversation->get('pinned')->value);
    $conversation->set('pinned', $next);
    $conversation->save();
    return $next;
  }

}
