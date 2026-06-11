<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query_mock\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\dkan_ai_query_mock\Service\ScenarioLoader;
use Drupal\dkan_ai_query_mock\Service\ScenarioMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Picker for the active mock scenario.
 *
 * Writes to State key `dkan_ai_query_mock.active_scenario`. Operators
 * can also override per-browser via the `dkan_aiq_scenario` cookie or per-call
 * via the `X-DKAN-Aiq-Scenario` request header — see ScenarioMatcher for the
 * full precedence chain.
 */
class MockScenarioPickerForm extends FormBase {

  public function __construct(
    private readonly ScenarioLoader $loader,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dkan_ai_query_mock.scenario_loader'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dkan_ai_query_mock_scenario_picker';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $scenarios = $this->loader->all();
    $options = ['' => $this->t('— Auto-match by question —')];
    $rows = [];
    foreach ($scenarios as $id => $scenario) {
      $options[$id] = $id;
      $rows[] = [
        $id,
        trim($scenario->description),
        count($scenario->turns) . ' turns',
        empty($scenario->match['question_contains']) ? '—' : implode(', ', $scenario->match['question_contains']),
      ];
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Selecting a scenario forces the <code>dkan_aiq_mock</code> provider to replay it for every chat call site-wide. Set the chat default at <a href=":settings">@url</a> to <code>dkan_aiq_mock / scripted</code> to activate the mock.',
        [
          ':settings' => Url::fromUri('base:/admin/config/ai/settings')->toString(),
          '@url' => '/admin/config/ai/settings',
        ],
      ) . '</p>',
    ];

    $form['active'] = [
      '#type' => 'select',
      '#title' => $this->t('Active scenario (site-wide)'),
      '#options' => $options,
      '#default_value' => (string) ($this->state->get(ScenarioMatcher::STATE_KEY) ?? ''),
      '#description' => $this->t('Stored in <code>state</code>. Per-browser cookie (<code>@cookie</code>) and per-call header (<code>@header</code>) overrides take precedence.', [
        '@cookie' => ScenarioMatcher::COOKIE,
        '@header' => ScenarioMatcher::HEADER,
      ]),
    ];

    $form['scenarios'] = [
      '#type' => 'details',
      '#title' => $this->t('Available scenarios'),
      '#open' => TRUE,
    ];
    $form['scenarios']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Id'),
        $this->t('Description'),
        $this->t('Turns'),
        $this->t('Auto-match needles'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No scenarios discovered. Add YAML files under <code>scenarios/</code>.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save active scenario'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $value = (string) $form_state->getValue('active');
    if ($value === '') {
      $this->state->delete(ScenarioMatcher::STATE_KEY);
      $this->messenger()->addStatus($this->t('Active scenario cleared. ScenarioMatcher will fall back to question_contains auto-match.'));
      return;
    }
    if ($this->loader->get($value) === NULL) {
      $this->messenger()->addError($this->t('Unknown scenario id: @id', ['@id' => $value]));
      return;
    }
    $this->state->set(ScenarioMatcher::STATE_KEY, $value);
    $this->messenger()->addStatus($this->t('Active scenario set to <code>@id</code>.', ['@id' => $value]));
  }

}
