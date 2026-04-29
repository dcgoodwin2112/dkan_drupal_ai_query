<?php

namespace Drupal\dkan_drupal_ai_query\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\dkan_drupal_ai_query\DatasetCaveatInterface;

/**
 * Defines the DatasetCaveat config entity.
 *
 * One record per DKAN dataset. The config entity machine `id` is the
 * dataset UUID with dashes replaced by underscores; the original UUID is
 * preserved in `dataset_uuid`. Surfaces curator-authored notes (suppression
 * rules, column-level gotchas, freshness/coverage windows, code lists) to
 * the dkan_data_query agent through DatasetCaveatRegistry.
 *
 * @ConfigEntityType(
 *   id = "dataset_caveat",
 *   label = @Translation("Dataset caveat"),
 *   label_collection = @Translation("Dataset caveats"),
 *   label_singular = @Translation("dataset caveat"),
 *   label_plural = @Translation("dataset caveats"),
 *   handlers = {
 *     "list_builder" = "Drupal\dkan_drupal_ai_query\DatasetCaveatListBuilder",
 *     "form" = {
 *       "add" = "Drupal\dkan_drupal_ai_query\Form\DatasetCaveatForm",
 *       "edit" = "Drupal\dkan_drupal_ai_query\Form\DatasetCaveatForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   admin_permission = "administer dkan dataset caveats",
 *   config_prefix = "caveat",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "dataset_uuid",
 *     "suppression",
 *     "column_caveats",
 *     "freshness",
 *     "code_lists",
 *   },
 *   links = {
 *     "collection" = "/admin/config/dkan/ai-query/caveats",
 *     "add-form" = "/admin/config/dkan/ai-query/caveats/add",
 *     "edit-form" = "/admin/config/dkan/ai-query/caveats/{dataset_caveat}/edit",
 *     "delete-form" = "/admin/config/dkan/ai-query/caveats/{dataset_caveat}/delete",
 *   }
 * )
 */
class DatasetCaveat extends ConfigEntityBase implements DatasetCaveatInterface {

  /**
   * The machine name (dataset UUID with dashes replaced by underscores).
   *
   * @var string
   */
  protected $id;

  /**
   * The dataset title at time of save (denormalized for the list builder).
   *
   * @var string
   */
  protected $label;

  /**
   * The original DKAN dataset UUID (with dashes).
   *
   * @var string
   */
  protected $dataset_uuid = '';

  /**
   * Free-text suppression / data-quality note.
   *
   * @var string|null
   */
  protected $suppression;

  /**
   * Map of column name => caveat text.
   *
   * @var array<string, string>
   */
  protected $column_caveats = [];

  /**
   * Freshness sub-record: {updated: date, coverage: string}.
   *
   * @var array<string, string>
   */
  protected $freshness = [];

  /**
   * Map of column name => list of expected values.
   *
   * @var array<string, array<int, string>>
   */
  protected $code_lists = [];

  /**
   * Convert a dataset UUID to the matching config entity machine id.
   */
  public static function uuidToId(string $uuid): string {
    return str_replace('-', '_', $uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDatasetUuid(): string {
    return (string) $this->dataset_uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function getSuppression(): ?string {
    $value = $this->suppression;
    return ($value === NULL || $value === '') ? NULL : $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getColumnCaveats(): array {
    return is_array($this->column_caveats) ? $this->column_caveats : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFreshness(): array {
    return is_array($this->freshness) ? array_filter($this->freshness, static fn($v) => $v !== '' && $v !== NULL) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCodeLists(): array {
    return is_array($this->code_lists) ? $this->code_lists : [];
  }

  /**
   * {@inheritdoc}
   */
  public function toCaveatArray(): array {
    $out = [];
    if (($s = $this->getSuppression()) !== NULL) {
      $out['suppression'] = $s;
    }
    if ($cc = $this->getColumnCaveats()) {
      $out['column_caveats'] = $cc;
    }
    if ($f = $this->getFreshness()) {
      $out['freshness'] = $f;
    }
    if ($cl = $this->getCodeLists()) {
      $out['code_lists'] = $cl;
    }
    return $out;
  }

}
