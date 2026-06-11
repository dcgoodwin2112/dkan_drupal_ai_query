<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query_mock\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\dkan_ai_query_mock\Scenario;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers and parses scenario YAML files for the mock provider.
 *
 * Scenarios live in two locations:
 *   - <module>/scenarios/*.yml — canonical fixtures shipped with the module
 *   - <module>/tests/scenarios/*.yml — test-only fixtures (loaded last, can
 *     shadow canonical ones for kernel tests)
 *
 * Results are cached in the default cache bin keyed off the file mtimes so
 * scenario edits show up immediately on cache rebuild but no per-request I/O
 * is needed in the steady state.
 */
class ScenarioLoader {

  private const CACHE_KEY = 'dkan_ai_query_mock:scenarios';

  /**
   * In-process snapshot of discovered scenarios, keyed by id.
   *
   * @var array<string, \Drupal\dkan_ai_query_mock\Scenario>|null
   */
  private ?array $scenarios = NULL;

  public function __construct(
    private readonly ExtensionPathResolver $pathResolver,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Returns all scenarios keyed by id.
   *
   * @return array<string, \Drupal\dkan_ai_query_mock\Scenario>
   *   Map of scenario id to Scenario.
   */
  public function all(): array {
    if ($this->scenarios !== NULL) {
      return $this->scenarios;
    }
    if ($cached = $this->cache->get(self::CACHE_KEY)) {
      $this->scenarios = $cached->data;
      return $this->scenarios;
    }
    $this->scenarios = $this->discover();
    $this->cache->set(self::CACHE_KEY, $this->scenarios, CacheBackendInterface::CACHE_PERMANENT, ['dkan_ai_query_mock:scenarios']);
    return $this->scenarios;
  }

  /**
   * Returns one scenario by id, or NULL if not found.
   */
  public function get(string $id): ?Scenario {
    return $this->all()[$id] ?? NULL;
  }

  /**
   * Forces a re-discovery on next access.
   *
   * Use after writing scenario files in tests, or when a hook on
   * cache rebuild needs to flush the in-memory snapshot.
   */
  public function reset(): void {
    $this->scenarios = NULL;
    $this->cache->delete(self::CACHE_KEY);
  }

  /**
   * Walks the scenario directories and parses every *.yml file.
   *
   * @return array<string, \Drupal\dkan_ai_query_mock\Scenario>
   *   Discovered scenarios keyed by id.
   */
  private function discover(): array {
    $modulePath = $this->pathResolver->getPath('module', 'dkan_ai_query_mock');
    $directories = [
      $modulePath . '/scenarios',
      $modulePath . '/tests/scenarios',
    ];
    $scenarios = [];
    foreach ($directories as $dir) {
      if (!is_dir($dir)) {
        continue;
      }
      foreach (glob($dir . '/*.yml') ?: [] as $file) {
        $parsed = $this->parseFile($file);
        $scenarios[$parsed->id] = $parsed;
      }
    }
    return $scenarios;
  }

  /**
   * Parses a single YAML file into a Scenario.
   *
   * Validates the minimum required structure so a malformed file fails loudly
   * at load time rather than mid-conversation. We do not validate that
   * referenced tool names exist on the agent — that check happens in the
   * provider when the tool is actually about to be emitted, since the agent's
   * tools list is request-scoped.
   */
  private function parseFile(string $path): Scenario {
    $stem = pathinfo($path, PATHINFO_FILENAME);
    try {
      $data = Yaml::parseFile($path);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException(sprintf('Mock scenario %s failed to parse: %s', $path, $e->getMessage()), 0, $e);
    }
    if (!is_array($data)) {
      throw new \RuntimeException(sprintf('Mock scenario %s did not parse to an array.', $path));
    }
    $id = (string) ($data['id'] ?? $stem);
    $description = (string) ($data['description'] ?? '');
    $match = is_array($data['match'] ?? NULL) ? $data['match'] : [];
    $turns = $data['turns'] ?? [];
    if (!is_array($turns) || $turns === []) {
      throw new \RuntimeException(sprintf('Mock scenario %s has no turns.', $path));
    }
    foreach ($turns as $i => $turn) {
      if (!is_array($turn) || !isset($turn['type'])) {
        throw new \RuntimeException(sprintf('Mock scenario %s turn %d is missing the "type" key.', $path, $i));
      }
      if ($turn['type'] === 'tool_calls') {
        if (empty($turn['calls']) || !is_array($turn['calls'])) {
          throw new \RuntimeException(sprintf('Mock scenario %s turn %d (tool_calls) needs a non-empty "calls" array.', $path, $i));
        }
        foreach ($turn['calls'] as $j => $call) {
          if (empty($call['name'])) {
            throw new \RuntimeException(sprintf('Mock scenario %s turn %d call %d has no tool name.', $path, $i, $j));
          }
        }
      }
      elseif ($turn['type'] === 'final_answer') {
        if (!isset($turn['content'])) {
          throw new \RuntimeException(sprintf('Mock scenario %s turn %d (final_answer) needs a "content" string.', $path, $i));
        }
      }
      else {
        throw new \RuntimeException(sprintf('Mock scenario %s turn %d has unknown type "%s" (expected tool_calls|final_answer).', $path, $i, $turn['type']));
      }
    }
    return new Scenario($id, $description, $match, $turns);
  }

}
