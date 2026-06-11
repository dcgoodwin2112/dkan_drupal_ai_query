<?php

namespace Drupal\dkan_ai_query\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Psr\Log\LoggerInterface;

/**
 * Generates short follow-up question chips from a question + answer pair.
 *
 * One lightweight LLM call per turn. Prefers Anthropic Haiku for cost; falls
 * back to whatever the site has configured as the default chat provider.
 * Returns [] silently on any failure — suggestions are a nicety, never a
 * blocker for the main /start response.
 */
class SuggestionGenerator {

  /**
   * Preferred fast/cheap model for suggestion generation.
   */
  protected const PREFERRED_PROVIDER = 'anthropic';
  protected const PREFERRED_MODEL = 'claude-haiku-4-5-20251001';

  public function __construct(
    protected AiProviderPluginManager $providerManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Generate up to 3 follow-up questions.
   *
   * @return string[]
   *   Plain question strings.
   */
  public function generate(string $question, string $answer): array {
    $question = trim($question);
    $answer = trim($answer);
    if ($question === '' || $answer === '') {
      return [];
    }

    [$providerId, $modelId] = $this->resolveProvider();
    if ($providerId === '' || $modelId === '') {
      return [];
    }

    try {
      $provider = $this->providerManager->createInstance($providerId);
      $systemPrompt = 'You suggest follow-up questions for a data query interface. '
        . "Given the user's question and the assistant's answer, generate exactly 3 short, "
        . 'specific follow-up questions that would help the user explore the data further. '
        . 'Return ONLY a JSON array of strings, nothing else. Example: ["question 1","question 2","question 3"]';
      $userMessage = "User asked: {$question}\n\nAssistant answered: " . mb_substr($answer, 0, 500);

      $input = new ChatInput([
        new ChatMessage('system', $systemPrompt),
        new ChatMessage('user', $userMessage),
      ]);
      $output = $provider->chat($input, $modelId)->getNormalized();
      $text = trim((string) $output->getText());

      // Strip optional ```json ... ``` fences.
      if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
        $text = trim($m[1]);
      }
      $parsed = json_decode($text, TRUE);
      if (!is_array($parsed)) {
        return [];
      }
      $strings = array_values(array_filter($parsed, fn($v) => is_string($v) && trim($v) !== ''));
      return array_slice($strings, 0, 3);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Follow-up suggestion generation failed: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Pick a provider+model, preferring the cheap default; fall back to global.
   */
  protected function resolveProvider(): array {
    try {
      $preferred = $this->providerManager->createInstance(self::PREFERRED_PROVIDER);
      if ($preferred->isUsable('chat')) {
        return [self::PREFERRED_PROVIDER, self::PREFERRED_MODEL];
      }
    }
    catch (\Throwable $e) {
      // Fall through.
    }
    $default = $this->providerManager->getDefaultProviderForOperationType('chat');
    if (!empty($default['provider_id']) && !empty($default['model_id'])) {
      return [$default['provider_id'], $default['model_id']];
    }
    return ['', ''];
  }

}
