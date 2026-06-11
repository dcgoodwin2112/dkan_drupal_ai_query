<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query\Eval;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and validates golden cases from a YAML file.
 */
class GoldenCaseLoader {

  /**
   * Parse and validate a golden set YAML file.
   *
   * @param string $path
   *   Absolute path to the YAML file.
   *
   * @return \Drupal\dkan_ai_query\Eval\GoldenCase[]
   *   Cases in the order they appear in the file.
   */
  public function load(string $path): array {
    if (!is_file($path)) {
      throw new \RuntimeException("Golden set file not found: {$path}");
    }
    $raw = file_get_contents($path);
    if ($raw === FALSE) {
      throw new \RuntimeException("Cannot read golden set file: {$path}");
    }
    $parsed = Yaml::parse($raw);
    if (!is_array($parsed) || !isset($parsed['cases']) || !is_array($parsed['cases'])) {
      throw new \RuntimeException("Golden set must be a YAML mapping with a 'cases' list at: {$path}");
    }
    $cases = [];
    $seenIds = [];
    foreach ($parsed['cases'] as $i => $row) {
      if (!is_array($row)) {
        throw new \RuntimeException("Case at index {$i} is not a mapping in: {$path}");
      }
      $case = GoldenCase::fromArray($row);
      if (isset($seenIds[$case->id])) {
        throw new \RuntimeException("Duplicate case id '{$case->id}' in: {$path}");
      }
      $seenIds[$case->id] = TRUE;
      $cases[] = $case;
    }
    return $cases;
  }

}
