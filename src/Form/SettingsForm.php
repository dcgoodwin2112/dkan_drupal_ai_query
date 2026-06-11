<?php

namespace Drupal\dkan_ai_query\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dkan_ai_query\Service\AgentPromptSync;
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
   * The agent prompt sync service.
   *
   * @var \Drupal\dkan_ai_query\Service\AgentPromptSync
   */
  protected AgentPromptSync $agentPromptSync;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    AiProviderPluginManager $provider_manager,
    EntityTypeManagerInterface $entity_type_manager,
    AgentPromptSync $agent_prompt_sync,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->providerManager = $provider_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->agentPromptSync = $agent_prompt_sync;
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
      $container->get('dkan_ai_query.agent_prompt_sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dkan_ai_query.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dkan_ai_query_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dkan_ai_query.settings');

    // Live model list, scoped to providers that are usable for chat AND
    // models that declare the ChatTools capability — the agent loop only
    // works with function-calling models, so non-tool variants (image,
    // audio, transcription, etc.) shouldn't be selectable here either.
    $modelOptions = $this->providerManager->getSimpleProviderModelOptions(
      'chat',
      FALSE,
      TRUE,
      [AiModelCapability::ChatTools],
    );
    $modelOptions = array_map('strval', $modelOptions);
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

    // Collapsible wrapper so the checkbox list (often long once multiple
    // providers are configured) doesn't dominate the form. Auto-opens
    // when a saved restriction exists so admins land on their selection.
    $savedAllowed = (array) ($config->get('allowed_models') ?? []);
    $form['provider']['allowed_models_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Allowed models'),
      '#description' => $this->t('Optional. Restricts the widget\'s model dropdown to the models you check below. Leave all unchecked to allow every chat-tools-capable model from configured providers. The default model above is independent of this list — pick a default that is also checked here, or end users will fall back to the first allowed model.'),
      '#open' => !empty($savedAllowed),
      '#tree' => FALSE,
    ];
    $form['provider']['allowed_models_section']['allowed_models'] = [
      '#type' => 'checkboxes',
      '#options' => $modelOptions,
      '#default_value' => array_values($savedAllowed),
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
      '#title' => $this->t('Show "Agent diagnostics" panel'),
      '#default_value' => $config->get('show_debug_panel') ?? FALSE,
      '#description' => $this->t('Display a collapsible panel below the conversation showing per-tool timing, step structure, errors, and raw arguments. Aimed at developers and operators debugging agent runs — not end users.'),
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
      '#title' => $this->t('"API call" section in result details'),
      '#default_value' => $config->get('show_api_call') ?? TRUE,
      '#description' => $this->t('Exposes the underlying DKAN datastore API request the agent issued. Appears as a collapsible section inside the result details panel.'),
    ];

    $form['actions_group']['show_sql'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('"SQL" section in result details'),
      '#default_value' => $config->get('show_sql') ?? TRUE,
      '#description' => $this->t('Exposes the equivalent SQL for the underlying query. Appears as a collapsible section inside the result details panel.'),
    ];

    $form['actions_group']['show_provenance'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show / Hide result details button'),
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
      '#title' => $this->t('Copy buttons in API call and SQL sections'),
      '#default_value' => $config->get('show_copy_buttons') ?? TRUE,
      '#description' => $this->t('Adds a clipboard copy action inside the API call and SQL sections of the result details panel.'),
    ];

    $form['actions_group']['show_aux_tool_calls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show "Supporting data" panel'),
      '#default_value' => $config->get('show_aux_tool_calls') ?? FALSE,
      '#description' => $this->t('Adds a collapsible panel below the answer listing tool calls the agent made that don\'t produce a primary table — computed statistics, data dictionaries, and column-level stats. Off by default; turn on for power-user / admin contexts where users want to verify the agent\'s work.'),
    ];

    $form['actions_group']['show_rest_playground_sidebar'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show REST API playground sidebar'),
      '#default_value' => $config->get('show_rest_playground_sidebar') ?? FALSE,
      '#description' => $this->t('Adds a "Try in API playground" button to datastore query results. Opens a right-side sidebar with the editable REST request body and a Run button so users can experiment with the same call — tweak conditions, change pagination, re-run, and inspect the raw JSON response. Off by default; turn on for power-user / internal contexts.'),
    ];

    $form['actions_group']['show_simple_table_artifacts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Render table for non-query tool calls'),
      '#default_value' => $config->get('show_simple_table_artifacts') ?? TRUE,
      '#description' => $this->t('When enabled, sample rows, distinct value lookups, column searches, dataset / distribution / schema listings render as in-bubble tables (with CSV download). Disabling this hides the table for those tools — only datastore queries continue to show one.'),
    ];

    $form['actions_group']['show_method_summary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show method summary'),
      '#default_value' => $config->get('show_method_summary') ?? TRUE,
      '#description' => $this->t('Adds a one-line summary above the tables describing how the agent reached its answer ("Answered using 2 datastore queries and 1 supporting lookup.").'),
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

    $form['runtime']['enable_raw_query_tool'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable raw passthrough query tool'),
      '#default_value' => (bool) ($config->get('enable_raw_query_tool') ?? FALSE),
      '#description' => $this->t('Registers <code>query_datastore_raw</code> on the agent. Lets the agent submit a verbatim DKAN DatastoreQuery payload (nested OR groups, three-way joins, compound expressions) when the flat <code>query_datastore</code> tools cannot express the shape. The flat tools remain the default per the system prompt — raw is the escape hatch. Off by default; enable after telemetry confirms behavior.'),
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
      '#description' => $this->t('Server-side logging to the <code>dkan_ai_query</code> channel. View entries with <code>drush watchdog:show --type=dkan_ai_query</code>. Independent of the in-widget Agent diagnostics panel above.'),
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
    $enableRawQueryTool = (bool) $form_state->getValue('enable_raw_query_tool');

    $this->config('dkan_ai_query.settings')
      ->set('default_model', $form_state->getValue('default_model'))
      ->set('allowed_models', array_values(array_filter((array) $form_state->getValue('allowed_models'))))
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
      ->set('show_simple_table_artifacts', (bool) $form_state->getValue('show_simple_table_artifacts'))
      ->set('show_aux_tool_calls', (bool) $form_state->getValue('show_aux_tool_calls'))
      ->set('show_rest_playground_sidebar', (bool) $form_state->getValue('show_rest_playground_sidebar'))
      ->set('show_method_summary', (bool) $form_state->getValue('show_method_summary'))
      ->set('enable_raw_query_tool', $enableRawQueryTool)
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
      // Mirror the raw-tool flag onto the agent's tools list. Idempotent.
      $this->agentPromptSync->syncTools($enableRawQueryTool);
    }

    parent::submitForm($form, $form_state);
  }

}
