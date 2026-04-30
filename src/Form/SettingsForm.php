<?php

namespace Drupal\dkan_drupal_ai_query\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Widget display + default-model settings form.
 *
 * API keys are not configured here — they live as Key entities (see the
 * `key` module). Manage them at /admin/config/system/keys.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The AI provider plugin manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $providerManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    AiProviderPluginManager $provider_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->providerManager = $provider_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dkan_drupal_ai_query.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dkan_drupal_ai_query_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dkan_drupal_ai_query.settings');

    // Live model list, scoped to providers that are usable for chat.
    $modelOptions = $this->providerManager->getSimpleProviderModelOptions('chat', FALSE);
    $hasModels = !empty($modelOptions);

    $description = $hasModels
      ? $this->t('Used when the widget does not specify a model. Choose "Use global default" to defer to the site-wide default at <a href="@global">/admin/config/ai/settings</a>.', [
        '@global' => '/admin/config/ai/settings',
      ])
      : $this->t('No AI providers are usable yet. Configure a key at <a href="@keys">/admin/config/system/keys</a> and wire it on a provider page (e.g. <a href="@anthropic">Anthropic</a> or <a href="@openai">OpenAI</a>).', [
        '@keys' => '/admin/config/system/keys',
        '@anthropic' => '/admin/config/ai/providers/anthropic',
        '@openai' => '/admin/config/ai/providers/openai',
      ]);

    $form['provider'] = [
      '#type' => 'details',
      '#title' => $this->t('Provider & model'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['provider']['default_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default model'),
      '#options' => ['' => $this->t('Use global default')] + $modelOptions,
      '#default_value' => (string) ($config->get('default_model') ?? ''),
      '#description' => $description,
      '#disabled' => !$hasModels,
    ];

    $form['chrome'] = [
      '#type' => 'details',
      '#title' => $this->t('Widget interface'),
      '#description' => $this->t('Controls for the widget shell — what surrounds the conversation.'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['chrome']['show_model_selector'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show model selector'),
      '#default_value' => $config->get('show_model_selector') ?? TRUE,
    ];

    $form['chrome']['show_examples'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show example questions'),
      '#default_value' => $config->get('show_examples') ?? TRUE,
    ];

    $form['chrome']['show_follow_up_suggestions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show follow-up suggestion chips'),
      '#default_value' => $config->get('show_follow_up_suggestions') ?? TRUE,
      '#description' => $this->t('After each answer, generate three short follow-up question chips via a lightweight LLM call.'),
    ];

    $form['chrome']['show_debug_panel'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show tool-calls debug panel'),
      '#default_value' => $config->get('show_debug_panel') ?? FALSE,
      '#description' => $this->t('Display a collapsible panel showing tool calls and arguments to end users.'),
    ];

    $form['actions_group'] = [
      '#type' => 'details',
      '#title' => $this->t('Result actions'),
      '#description' => $this->t('Per-result buttons rendered alongside each data table. Disable to hide a button across all results.'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['actions_group']['show_table_toggle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show / Hide table toggle'),
      '#default_value' => $config->get('show_table_toggle') ?? TRUE,
      '#description' => $this->t('Reveals the data table on demand. With this off, results stay collapsed and users see only the answer summary.'),
    ];

    $form['actions_group']['show_api_call'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show / Hide API call button'),
      '#default_value' => $config->get('show_api_call') ?? TRUE,
      '#description' => $this->t('Exposes the underlying DKAN datastore API request the agent issued.'),
    ];

    $form['actions_group']['show_sql'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show / Hide SQL button'),
      '#default_value' => $config->get('show_sql') ?? TRUE,
      '#description' => $this->t('Exposes the equivalent SQL for the underlying query.'),
    ];

    $form['actions_group']['show_provenance'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show / Hide provenance button'),
      '#default_value' => $config->get('show_provenance') ?? TRUE,
      '#description' => $this->t('Reveals the dataset, distribution, and resource ids the result is derived from.'),
    ];

    $form['actions_group']['show_download_csv'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Download CSV button'),
      '#default_value' => $config->get('show_download_csv') ?? TRUE,
      '#description' => $this->t('Lets users save the visible result rows as a CSV file.'),
    ];

    $form['actions_group']['show_copy_buttons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Copy API / SQL buttons'),
      '#default_value' => $config->get('show_copy_buttons') ?? TRUE,
      '#description' => $this->t('Adds a clipboard copy action inside the API call and SQL panels.'),
    ];

    $form['history'] = [
      '#type' => 'details',
      '#title' => $this->t('Conversation history'),
      '#description' => $this->t('Persistent chat history for authenticated users.'),
      '#open' => FALSE,
      '#tree' => FALSE,
    ];

    $form['history']['save_chat_history'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save chat history'),
      '#default_value' => $config->get('save_chat_history') ?? TRUE,
      '#description' => $this->t('Save conversations for authenticated users and show the history sidebar.'),
    ];

    $form['history']['conversation_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Retention (days)'),
      '#min' => 0,
      '#default_value' => (int) ($config->get('conversation_retention_days') ?? 0),
      '#description' => $this->t('Saved conversations older than this are deleted on cron. <code>0</code> disables the purge. Pinned conversations are kept regardless.'),
    ];

    $form['runtime'] = [
      '#type' => 'details',
      '#title' => $this->t('Agent runtime'),
      '#description' => $this->t("Limits applied to the agent's solve loop."),
      '#open' => FALSE,
      '#tree' => FALSE,
    ];

    $form['runtime']['max_iterations'] = [
      '#type' => 'number',
      '#title' => $this->t('Max agent iterations'),
      '#min' => 1,
      '#max' => 50,
      '#default_value' => (int) ($config->get('max_iterations') ?? 10),
      '#description' => $this->t("Cap on the agent's tool-call loop per question. Higher values let the agent recover from missteps; lower values cut cost. Saved here is mirrored to the <code>dkan_data_query</code> agent's <code>max_loops</code>."),
    ];

    $form['logging'] = [
      '#type' => 'details',
      '#title' => $this->t('Logging'),
      '#description' => $this->t('Server-side logging to the <code>dkan_drupal_ai_query</code> channel. View entries with <code>drush watchdog:show --type=dkan_drupal_ai_query</code>. Independent of the user-facing tool-calls debug panel above.'),
      '#open' => FALSE,
      '#tree' => FALSE,
    ];

    $form['logging']['debug_log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Debug log level'),
      '#options' => [
        'off' => $this->t('Off'),
        'info' => $this->t('Info — questions and final answers'),
        'debug' => $this->t('Debug — also tool calls and arguments'),
      ],
      '#default_value' => (string) ($config->get('debug_log_level') ?? 'off'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $maxIterations = max(1, (int) $form_state->getValue('max_iterations'));
    $debugLevel = (string) $form_state->getValue('debug_log_level');
    if (!in_array($debugLevel, ['off', 'info', 'debug'], TRUE)) {
      $debugLevel = 'off';
    }

    $this->config('dkan_drupal_ai_query.settings')
      ->set('default_model', $form_state->getValue('default_model'))
      ->set('show_model_selector', (bool) $form_state->getValue('show_model_selector'))
      ->set('show_examples', (bool) $form_state->getValue('show_examples'))
      ->set('show_debug_panel', (bool) $form_state->getValue('show_debug_panel'))
      ->set('save_chat_history', (bool) $form_state->getValue('save_chat_history'))
      ->set('show_follow_up_suggestions', (bool) $form_state->getValue('show_follow_up_suggestions'))
      ->set('show_table_toggle', (bool) $form_state->getValue('show_table_toggle'))
      ->set('show_api_call', (bool) $form_state->getValue('show_api_call'))
      ->set('show_sql', (bool) $form_state->getValue('show_sql'))
      ->set('show_provenance', (bool) $form_state->getValue('show_provenance'))
      ->set('show_download_csv', (bool) $form_state->getValue('show_download_csv'))
      ->set('show_copy_buttons', (bool) $form_state->getValue('show_copy_buttons'))
      ->set('max_iterations', $maxIterations)
      ->set('conversation_retention_days', max(0, (int) $form_state->getValue('conversation_retention_days')))
      ->set('debug_log_level', $debugLevel)
      ->save();

    // Mirror max_iterations onto the dkan_data_query agent's max_loops so the
    // agent config entity remains the runtime source of truth at solve time.
    if ($this->entityTypeManager->hasDefinition('ai_agent')) {
      $agentStorage = $this->entityTypeManager->getStorage('ai_agent');
      if ($agent = $agentStorage->load('dkan_data_query')) {
        if ((int) $agent->get('max_loops') !== $maxIterations) {
          $agent->set('max_loops', $maxIterations)->save();
        }
      }
    }

    parent::submitForm($form, $form_state);
  }

}
