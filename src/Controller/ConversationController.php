<?php

namespace Drupal\dkan_drupal_ai_query\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_drupal_ai_query\Service\ConversationStorage;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Conversation list / load / delete / pin endpoints.
 */
class ConversationController {

  public function __construct(
    protected ConversationStorage $storage,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * List the current user's conversations.
   */
  public function list(): JsonResponse {
    return new JsonResponse(
      $this->storage->listForUser((int) $this->currentUser->id())
    );
  }

  /**
   * Load a conversation and all its messages.
   */
  public function load(string $id): JsonResponse {
    $conversation = $this->storage->loadConversation((int) $id);
    if (!$conversation) {
      return new JsonResponse(['error' => 'Conversation not found.'], 404);
    }
    if (!$this->canAccess($conversation)) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }
    return new JsonResponse([
      'id' => (int) $conversation->id(),
      'title' => $conversation->get('title')->value,
      'dataset_id' => $conversation->get('dataset_id')->value,
      'pinned' => (bool) $conversation->get('pinned')->value,
      'messages' => $this->storage->getMessages((int) $id),
    ]);
  }

  /**
   * Delete a conversation and its messages.
   */
  public function delete(string $id): JsonResponse {
    $conversation = $this->storage->loadConversation((int) $id);
    if (!$conversation) {
      return new JsonResponse(['error' => 'Conversation not found.'], 404);
    }
    if (!$this->canAccess($conversation)) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }
    $this->storage->deleteConversation((int) $id);
    return new JsonResponse(['status' => 'deleted']);
  }

  /**
   * Toggle the pinned flag on a conversation.
   */
  public function togglePin(string $id): JsonResponse {
    $conversation = $this->storage->loadConversation((int) $id);
    if (!$conversation) {
      return new JsonResponse(['error' => 'Conversation not found.'], 404);
    }
    if (!$this->canAccess($conversation)) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }
    $pinned = $this->storage->togglePin((int) $id);
    return new JsonResponse(['pinned' => $pinned]);
  }

  /**
   * Owner-or-admin check.
   */
  protected function canAccess($conversation): bool {
    if ($this->currentUser->hasPermission('administer dkan drupal ai query conversations')) {
      return TRUE;
    }
    return (int) $conversation->get('uid')->target_id === (int) $this->currentUser->id();
  }

}
