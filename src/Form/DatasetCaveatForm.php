<?php

namespace Drupal\dkan_ai_query\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dkan_ai_query\Entity\DatasetCaveat;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Add / edit form for DatasetCaveat config entities.
 *
 * On add: a select of datasets sourced from MetastoreTools. The dataset
 * UUID becomes the join key (`dataset_uuid`) and a slugged form of it is
 * the entity machine `id`. Dataset selector is hidden on edit.
 *
 * column_caveats and code_lists are authored as YAML in textareas. Keeps
 * the initial form small; multi-row tables can come later.
 */
class DatasetCaveatForm extends EntityForm {

  /**
   * The metastore tools (catalog browser).
   */
  protected MetastoreTools $metastoreTools;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->metastoreTools = $container->get('dkan_query_tools.metastore');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->setMessenger($container->get('messenger'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\dkan_ai_query\Entity\DatasetCaveat $entity */
    $entity = $this->entity;
    $isNew = $entity->isNew();

    if ($isNew) {
      $form['dataset_uuid'] = [
        '#type' => 'select',
        '#title' => $this->t('Dataset'),
        '#required' => TRUE,
        '#options' => $this->buildDatasetOptions(),
        '#empty_option' => $this->t('- Select a dataset -'),
        '#description' => $this->t('Each dataset can have at most one caveat record. Datasets that already have one are excluded.'),
      ];
    }
    else {
      $form['dataset_uuid_display'] = [
        '#type' => 'item',
        '#title' => $this->t('Dataset'),
        '#markup' => $entity->label() . ' <code>' . $entity->getDatasetUuid() . '</code>',
      ];
      $form['dataset_uuid'] = [
        '#type' => 'value',
        '#value' => $entity->getDatasetUuid(),
      ];
    }

    $form['suppression'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Suppression / data-quality note'),
      '#default_value' => $entity->getSuppression() ?? '',
      '#rows' => 3,
      '#description' => $this->t('Free text. Surfaced verbatim to the AI agent. Example: "Counts under 10 are suppressed and reported as 0."'),
    ];

    $freshness = $entity->getFreshness();
    $form['freshness'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Freshness'),
    ];
    $form['freshness']['updated'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Updated'),
      '#default_value' => $freshness['updated'] ?? '',
      '#size' => 30,
      '#description' => $this->t('ISO date of the latest data point, e.g. 2024-01-15.'),
    ];
    $form['freshness']['coverage'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Coverage'),
      '#default_value' => $freshness['coverage'] ?? '',
      '#description' => $this->t('Plain-English range, e.g. "1991-2007" or "2013 reporting year".'),
    ];

    $form['column_caveats'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Column caveats (YAML)'),
      '#default_value' => $this->arrayToYaml($entity->getColumnCaveats()),
      '#rows' => 6,
      '#description' => $this->t('YAML mapping of column name to caveat text. Example:<br><code>rate_per_100k: "Per 100,000 population. ..."</code>'),
    ];

    $form['code_lists'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Code lists (YAML)'),
      '#default_value' => $this->arrayToYaml($entity->getCodeLists()),
      '#rows' => 6,
      '#description' => $this->t('YAML mapping of column name to a list of expected values. Example:<br><code>age_range:<br>&nbsp;&nbsp;- Adults<br>&nbsp;&nbsp;- Children</code>'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    /** @var \Drupal\dkan_ai_query\Entity\DatasetCaveat $entity */
    $entity = $this->entity;

    if ($entity->isNew()) {
      $uuid = (string) $form_state->getValue('dataset_uuid');
      if ($uuid !== '') {
        $id = DatasetCaveat::uuidToId($uuid);
        $existing = $this->entityTypeManager->getStorage('dataset_caveat')->load($id);
        if ($existing) {
          $form_state->setErrorByName('dataset_uuid', $this->t('A caveat record already exists for this dataset. Edit it instead.'));
        }
      }
    }

    foreach (['column_caveats', 'code_lists'] as $key) {
      $raw = (string) $form_state->getValue($key);
      if (trim($raw) === '') {
        continue;
      }
      try {
        $parsed = Yaml::parse($raw);
      }
      catch (ParseException $e) {
        $form_state->setErrorByName($key, $this->t('Invalid YAML: @msg', ['@msg' => $e->getMessage()]));
        continue;
      }
      if ($parsed === NULL) {
        continue;
      }
      if (!is_array($parsed)) {
        $form_state->setErrorByName($key, $this->t('Must be a YAML mapping (key: value), not a scalar or list.'));
        continue;
      }
      if ($key === 'column_caveats') {
        foreach ($parsed as $col => $text) {
          if (!is_string($col) || !is_string($text)) {
            $form_state->setErrorByName($key, $this->t('column_caveats: each entry must be column_name: text.'));
            break;
          }
        }
      }
      else {
        foreach ($parsed as $col => $values) {
          if (!is_string($col) || !is_array($values)) {
            $form_state->setErrorByName($key, $this->t('code_lists: each entry must be column_name: [value, value, ...].'));
            break;
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\dkan_ai_query\Entity\DatasetCaveat $entity */
    $entity = $this->entity;

    if ($entity->isNew()) {
      $uuid = (string) $form_state->getValue('dataset_uuid');
      $entity->set('id', DatasetCaveat::uuidToId($uuid));
      $entity->set('dataset_uuid', $uuid);
      $entity->set('label', $this->lookupDatasetTitle($uuid) ?: $uuid);
    }

    $entity->set('suppression', trim((string) $form_state->getValue('suppression')) ?: NULL);
    $entity->set('freshness', [
      'updated' => trim((string) $form_state->getValue(['freshness', 'updated'])),
      'coverage' => trim((string) $form_state->getValue(['freshness', 'coverage'])),
    ]);
    $entity->set('column_caveats', $this->parseYamlMap((string) $form_state->getValue('column_caveats')));
    $entity->set('code_lists', $this->parseYamlMap((string) $form_state->getValue('code_lists')));

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved caveat for @label.', ['@label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

  /**
   * Build a [uuid => "Title (uuid)"] options array, excluding existing caveats.
   */
  protected function buildDatasetOptions(): array {
    $existing = array_map(
      static fn(DatasetCaveat $c): string => $c->getDatasetUuid(),
      $this->entityTypeManager->getStorage('dataset_caveat')->loadMultiple(),
    );
    $existing = array_flip(array_filter($existing));

    $options = [];
    $offset = 0;
    do {
      $page = $this->metastoreTools->listDatasets($offset, 100);
      foreach ($page['datasets'] ?? [] as $row) {
        $uuid = $row['identifier'] ?? NULL;
        if (!$uuid || isset($existing[$uuid])) {
          continue;
        }
        $title = $row['title'] ?? $uuid;
        $options[$uuid] = sprintf('%s (%s)', $title, $uuid);
      }
      $offset += 100;
      $total = (int) ($page['total'] ?? 0);
    } while ($offset < $total);

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Look up the dataset title for a UUID; return NULL when not found.
   */
  protected function lookupDatasetTitle(string $uuid): ?string {
    $result = $this->metastoreTools->getDataset($uuid);
    return $result['dataset']['title'] ?? NULL;
  }

  /**
   * Render an associative array as a YAML block, or empty string when empty.
   */
  protected function arrayToYaml(array $value): string {
    if (!$value) {
      return '';
    }
    return Yaml::dump($value, 4, 2);
  }

  /**
   * Parse a YAML textarea value into an array (empty/scalar → []).
   */
  protected function parseYamlMap(string $raw): array {
    if (trim($raw) === '') {
      return [];
    }
    try {
      $parsed = Yaml::parse($raw);
    }
    catch (ParseException) {
      return [];
    }
    return is_array($parsed) ? $parsed : [];
  }

}
