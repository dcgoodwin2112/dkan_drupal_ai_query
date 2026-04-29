<?php

namespace Drupal\dkan_drupal_ai_query\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    AiProviderPluginManager $provider_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->providerManager = $provider_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
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

    $form['default_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default model'),
      '#options' => ['' => $this->t('Use global default')] + $modelOptions,
      '#default_value' => (string) ($config->get('default_model') ?? ''),
      '#description' => $description,
      '#disabled' => !$hasModels,
    ];

    $form['widget'] = [
      '#type' => 'details',
      '#title' => $this->t('Widget display'),
      '#open' => TRUE,
      '#tree' => FALSE,
    ];

    $form['widget']['show_model_selector'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show model selector'),
      '#default_value' => $config->get('show_model_selector') ?? TRUE,
    ];

    $form['widget']['show_examples'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show example questions'),
      '#default_value' => $config->get('show_examples') ?? TRUE,
    ];

    $form['widget']['show_debug_panel'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show tool-calls debug panel'),
      '#default_value' => $config->get('show_debug_panel') ?? FALSE,
      '#description' => $this->t('Display a collapsible panel showing tool calls and arguments.'),
    ];

    $form['widget']['save_chat_history'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save chat history'),
      '#default_value' => $config->get('save_chat_history') ?? TRUE,
      '#description' => $this->t('Save conversations for authenticated users; show the history sidebar.'),
    ];

    $form['widget']['show_follow_up_suggestions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show follow-up suggestion chips'),
      '#default_value' => $config->get('show_follow_up_suggestions') ?? TRUE,
      '#description' => $this->t('After each answer, generate three short follow-up question chips via a lightweight LLM call.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dkan_drupal_ai_query.settings')
      ->set('default_model', $form_state->getValue('default_model'))
      ->set('show_model_selector', (bool) $form_state->getValue('show_model_selector'))
      ->set('show_examples', (bool) $form_state->getValue('show_examples'))
      ->set('show_debug_panel', (bool) $form_state->getValue('show_debug_panel'))
      ->set('save_chat_history', (bool) $form_state->getValue('save_chat_history'))
      ->set('show_follow_up_suggestions', (bool) $form_state->getValue('show_follow_up_suggestions'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
