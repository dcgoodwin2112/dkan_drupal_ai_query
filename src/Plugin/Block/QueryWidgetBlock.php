<?php

namespace Drupal\dkan_drupal_ai_query\Plugin\Block;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Natural-language query widget block for DKAN dataset pages.
 *
 * @Block(
 *   id = "dkan_drupal_ai_query_widget",
 *   admin_label = @Translation("DKAN AI Query Widget"),
 *   category = @Translation("DKAN"),
 * )
 */
class QueryWidgetBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $providerManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->currentUser = $container->get('current_user');
    $instance->providerManager = $container->get('ai.provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['dataset_id' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['dataset_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dataset UUID'),
      '#default_value' => $this->configuration['dataset_id'] ?? '',
      '#description' => $this->t('Scope to a single dataset. Leave empty to show a dataset selector for cross-dataset queries.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['dataset_id'] = $form_state->getValue('dataset_id');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $datasetId = $this->configuration['dataset_id'] ?? '';
    $config = $this->configFactory->get('dkan_drupal_ai_query.settings');

    // Pull the live "provider__model" map from Drupal AI. Filtered to
    // providers that are usable (have keys configured); list updates itself
    // when a provider is enabled, configured, or releases new models.
    $models = $this->providerManager->getSimpleProviderModelOptions('chat', FALSE);
    // Stringify TranslatableMarkup labels for JSON serialization.
    $models = array_map('strval', $models);
    // The OpenAI provider returns its full models.list() regardless of the
    // 'chat' operation type, so strip variants that can't run a tool-using
    // agent loop: image generation, audio/TTS, transcription, realtime,
    // deep-research, search-api.
    $models = array_filter($models, function (string $label, string $value): bool {
      if (!str_starts_with($value, 'openai__')) {
        return TRUE;
      }
      $modelId = substr($value, strlen('openai__'));
      return !preg_match('/(image|audio|tts|transcribe|realtime|deep-research|search-preview|search-api)/i', $modelId);
    }, ARRAY_FILTER_USE_BOTH);

    // Resolve the default the same way NlQueryController will at submit time:
    // module config → site-wide default for "chat" → empty (JS shows the first
    // option). Keeps the dropdown's preselected entry consistent with what the
    // controller actually picks when no model is sent.
    $defaultModel = (string) ($config->get('default_model') ?? '');
    if ($defaultModel === '' || !str_contains($defaultModel, '__')) {
      $globalDefault = $this->providerManager->getDefaultProviderForOperationType('chat');
      if (!empty($globalDefault['provider_id']) && !empty($globalDefault['model_id'])) {
        $defaultModel = $globalDefault['provider_id'] . '__' . $globalDefault['model_id'];
      }
    }

    return [
      '#theme' => 'dkan_drupal_ai_query_widget',
      '#dataset_id' => $datasetId,
      '#cache' => ['max-age' => 0],
      '#attached' => [
        'library' => ['dkan_drupal_ai_query/widget'],
        'drupalSettings' => [
          'dkanAiQuery' => [
            'datasetId' => $datasetId,
            'models' => $models,
            'defaultModel' => $defaultModel,
            'showModelSelector' => $config->get('show_model_selector') ?? TRUE,
            'showExamples' => $config->get('show_examples') ?? TRUE,
            'showDebugPanel' => $config->get('show_debug_panel') ?? FALSE,
            'saveChatHistory' => $config->get('save_chat_history') ?? TRUE,
            'showFollowUpSuggestions' => $config->get('show_follow_up_suggestions') ?? TRUE,
            'showTableToggle' => $config->get('show_table_toggle') ?? TRUE,
            'showApiCall' => $config->get('show_api_call') ?? TRUE,
            'showSql' => $config->get('show_sql') ?? TRUE,
            'showProvenance' => $config->get('show_provenance') ?? TRUE,
            'showDownloadCsv' => $config->get('show_download_csv') ?? TRUE,
            'showCopyButtons' => $config->get('show_copy_buttons') ?? TRUE,
            'userAuthenticated' => $this->currentUser->isAuthenticated(),
          ],
        ],
      ],
    ];
  }

}
