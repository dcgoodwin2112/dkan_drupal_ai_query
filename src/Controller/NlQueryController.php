<?php

namespace Drupal\dkan_ai_query\Controller;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\ai_agents\Service\AgentStatus\Interfaces\AiAgentStatusPollerServiceInterface;
use Drupal\ai_agents\Task\Task;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\dkan_ai_query\Service\ArtifactStorage;
use Drupal\dkan_ai_query\Service\CatalogContextBuilder;
use Drupal\dkan_ai_query\Service\ConversationStorage;
use Drupal\dkan_ai_query\Service\RefusalCollector;
use Drupal\dkan_ai_query\Service\SuggestionGenerator;
use Drupal\dkan_ai_query\Service\SystemPromptLoader;
use Drupal\dkan_ai_query\Service\UnknownColumnCounter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoints for /start (blocking solve) and /poll (status events).
 *
 * Phase 1 wires the polling architecture from the plan. The /start endpoint
 * holds one PHP-FPM worker for the entire conversation turn. While solve()
 * runs, ai_agents writes status update items into PrivateTempStore keyed by
 * the runner_id (== thread_id). The browser polls /poll/{thread_id} in
 * parallel and renders new events as they appear.
 *
 * Phase 3 layers conversation persistence on top: each /start call either
 * creates a new conversation or appends a turn to an existing one. Prior
 * messages are passed to the agent as chat history so multi-turn flows
 * preserve context.
 */
class NlQueryController {

  public function __construct(
    protected AiAgentManager $agentManager,
    protected AiProviderPluginManager $providerManager,
    protected AiAgentStatusPollerServiceInterface $statusPoller,
    protected ArtifactStorage $artifacts,
    protected ConversationStorage $conversations,
    protected AccountProxyInterface $currentUser,
    protected LoggerInterface $logger,
    protected ConfigFactoryInterface $configFactory,
    protected SuggestionGenerator $suggestions,
    protected SystemPromptLoader $promptLoader,
    protected RefusalCollector $refusals,
    protected UnknownColumnCounter $unknownColumnCounter,
    protected CatalogContextBuilder $catalogContext,
  ) {}

  /**
   * Long-blocking endpoint: run the agent and return the final answer.
   *
   * Request body:
   *   {
   *     "question": "...",
   *     "thread_id": "<uuid-or-stable-string>",
   *     "resource_id": "abc123__1773...",   // optional hint
   *     "model": "anthropic__claude-...",   // optional
   *     "conversation_id": 42,              // optional, continues an existing
   *                                         // conversation; new one is created
   *                                         // when omitted
   *     "dataset_id": "..."                 // optional, used when creating a
   *                                         // new conversation
   *   }
   *
   * Response body:
   *   {
   *     "thread_id": "...",
   *     "conversation_id": 42,
   *     "answer": "...",
   *     "duration_ms": 12345,
   *     "solvability": ...,
   *     ...
   *   }
   */
  public function start(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE) ?: [];
    $question = trim((string) ($payload['question'] ?? ''));
    $threadId = trim((string) ($payload['thread_id'] ?? ''));
    $resourceId = trim((string) ($payload['resource_id'] ?? ''));
    $modelOption = trim((string) ($payload['model'] ?? ''));
    $conversationId = isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : 0;
    $datasetId = trim((string) ($payload['dataset_id'] ?? ''));

    if ($question === '') {
      return new JsonResponse(['error' => 'Missing question.'], 400);
    }
    if ($threadId === '') {
      return new JsonResponse(['error' => 'Missing thread_id.'], 400);
    }

    if (\PHP_SESSION_ACTIVE === session_status()) {
      session_write_close();
    }
    $this->artifacts->delete($threadId);

    $debugLevel = (string) ($this->configFactory->get('dkan_ai_query.settings')->get('debug_log_level') ?? 'off');
    if ($debugLevel === 'info' || $debugLevel === 'debug') {
      $this->logger->info('NL query start: thread=@t uid=@u question=@q', [
        '@t' => $threadId,
        '@u' => (int) $this->currentUser->id(),
        '@q' => mb_substr($question, 0, 500),
      ]);
    }

    // Resolve or create the conversation that this turn belongs to.
    $priorMessages = [];
    $conversationCreatedNow = FALSE;
    if ($conversationId > 0) {
      $conversation = $this->conversations->loadConversation($conversationId);
      if (!$conversation) {
        return new JsonResponse(['error' => 'Conversation not found.'], 404);
      }
      if (!$this->canAccessConversation($conversation)) {
        return new JsonResponse(['error' => 'Access denied.'], 403);
      }
      $priorMessages = $this->conversations->getMessages($conversationId);
    }
    else {
      $conversationId = $this->conversations->createConversation(
        (int) $this->currentUser->id(),
        mb_substr($question, 0, 80),
        $datasetId !== '' ? $datasetId : NULL,
      );
      $conversationCreatedNow = TRUE;
    }

    [$providerId, $modelId] = $this->resolveProviderAndModel($modelOption);

    // Build the task text with whatever scoping hints the caller supplied.
    // resource_id pins to a specific imported file; dataset_id pins to a
    // dataset (the LLM uses find_dataset_resources / list_distributions to
    // resolve resource ids within it).
    $hints = [];
    if ($resourceId !== '') {
      $hints[] = "Use resource_id={$resourceId}.";
    }
    elseif ($datasetId !== '') {
      $hints[] = "Scope queries to dataset_id={$datasetId}.";
    }
    // Pre-seed the dataset catalog so the agent can name titles in prose
    // without a list_datasets round trip and can match user references to
    // the right UUID without find_dataset_resources for known catalogs.
    $catalog = $this->catalogContext->build();
    if ($catalog !== '') {
      $hints[] = $catalog;
    }
    $taskText = $hints
      ? implode("\n\n", $hints) . "\n\n" . $question
      : $question;

    // Persist the user turn before solving so it survives a mid-solve crash.
    $this->conversations->addMessage($conversationId, 'user', $question);

    $start = hrtime(TRUE);
    $solvability = NULL;
    $answer = '';
    try {
      $provider = $this->providerManager->createInstance($providerId);
      $agent = $this->agentManager->createInstance('dkan_data_query');
      $agent->setTask(new Task($taskText));
      $agent->setAiProvider($provider);
      $agent->setModelName($modelId);
      $agent->setAiConfiguration([]);
      $agent->setCreateDirectly(TRUE);
      $agent->setRunnerId($threadId);
      $agent->setProgressThreadId($threadId);

      // Replay prior conversation as chat history so the agent has context.
      // Only role + content survive — tool calls/results from prior turns are
      // not rehydrated; rely on the LLM re-issuing tool calls if needed.
      if ($priorMessages) {
        $agent->setChatHistory($this->priorAsChatMessages($priorMessages));
      }

      $solvability = $agent->determineSolvability();
      if ($solvability === AiAgentInterface::JOB_SOLVABLE) {
        $answer = (string) $agent->solve();
      }
      else {
        $answer = "Agent did not consider the task solvable. Status: $solvability";
      }
    }
    catch (\Throwable $e) {
      // Drop a just-created conversation so a failed first turn does not
      // leave an orphan entry in the user's sidebar. For continuations we
      // keep the user message; the user expects their question preserved.
      if ($conversationCreatedNow) {
        $this->conversations->deleteConversation($conversationId);
        $conversationId = 0;
      }
      $this->logger->error('Agent solve failed for thread @t: @m', [
        '@t' => $threadId,
        '@m' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => $e->getMessage(),
        'thread_id' => $threadId,
        'conversation_id' => $conversationId,
      ], 500);
    }

    // Persist the assistant turn. Artifacts already include any refusal
    // captured by ArtifactCaptureSubscriber; we tag prompt_version so
    // historical messages can be correlated to the prompt that produced them.
    $turnArtifacts = $this->artifacts->load($threadId);
    $turnArtifacts[] = [
      'type' => 'meta',
      'prompt_version' => $this->promptLoader->activeVersion(),
    ];
    $this->conversations->addMessage($conversationId, 'assistant', $answer, $turnArtifacts);
    // Drop the in-process refusal record so a subsequent /start on the same
    // thread starts clean.
    $this->refusals->forget($threadId);
    $this->unknownColumnCounter->forget($threadId);

    // Generate follow-up question chips when enabled and there's an answer.
    $config = $this->configFactory->get('dkan_ai_query.settings');
    $suggestions = [];
    if (($config->get('show_follow_up_suggestions') ?? TRUE) && trim($answer) !== '') {
      $suggestions = $this->suggestions->generate($question, $answer);
    }

    $durationMs = (int) ((hrtime(TRUE) - $start) / 1e6);

    if ($debugLevel === 'info' || $debugLevel === 'debug') {
      $this->logger->info('NL query end: thread=@t duration_ms=@d solvability=@s answer=@a', [
        '@t' => $threadId,
        '@d' => $durationMs,
        '@s' => (string) $solvability,
        '@a' => mb_substr($answer, 0, $debugLevel === 'debug' ? 2000 : 200),
      ]);
    }

    return new JsonResponse([
      'thread_id' => $threadId,
      'conversation_id' => $conversationId,
      'answer' => $answer,
      'solvability' => $solvability,
      'provider' => $providerId,
      'model' => $modelId,
      'duration_ms' => $durationMs,
      'suggestions' => $suggestions,
    ]);
  }

  /**
   * Polling endpoint: return the full event log for a thread.
   */
  public function poll(string $thread_id): JsonResponse {
    $update = $this->statusPoller->getLatestStatusUpdates($thread_id);
    return new JsonResponse([
      'thread_id' => $thread_id,
      'events' => $update->toArray()['items'] ?? [],
      'artifacts' => $this->artifacts->load($thread_id),
    ]);
  }

  /**
   * Determine provider and model.
   *
   * Resolution order:
   *   1. Explicit per-turn option from the widget ("provider__model").
   *   2. Module-level default at /admin/dkan/ai-query/settings.
   *   3. Site-wide default for the chat operation type.
   *   4. Hardcoded fallback (Anthropic Haiku) for cold-start environments.
   */
  protected function resolveProviderAndModel(string $option): array {
    if ($option !== '' && str_contains($option, '__')) {
      return explode('__', $option, 2);
    }
    $configured = (string) ($this->configFactory->get('dkan_ai_query.settings')->get('default_model') ?? '');
    if ($configured !== '' && str_contains($configured, '__')) {
      return explode('__', $configured, 2);
    }
    $default = $this->providerManager->getDefaultProviderForOperationType('chat');
    return [
      $default['provider_id'] ?? 'anthropic',
      $default['model_id'] ?? 'claude-haiku-4-5-20251001',
    ];
  }

  /**
   * Convert stored conversation messages into ChatMessage instances.
   *
   * Skips empty messages and filters to user/assistant roles. Tool calls
   * from prior turns are intentionally dropped — the LLM re-derives them
   * from the rehydrated user/assistant exchange when relevant.
   */
  protected function priorAsChatMessages(array $messages): array {
    $out = [];
    foreach ($messages as $msg) {
      $role = $msg['role'] ?? '';
      $content = (string) ($msg['content'] ?? '');
      if ($content === '' || !in_array($role, ['user', 'assistant'], TRUE)) {
        continue;
      }
      $out[] = new ChatMessage($role, $content);
    }
    return $out;
  }

  /**
   * Owner-or-admin access check for a Conversation entity.
   */
  protected function canAccessConversation($conversation): bool {
    if ($this->currentUser->hasPermission('administer dkan ai query conversations')) {
      return TRUE;
    }
    return (int) $conversation->get('uid')->target_id === (int) $this->currentUser->id();
  }

}
