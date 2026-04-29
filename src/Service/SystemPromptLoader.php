<?php

namespace Drupal\dkan_drupal_ai_query\Service;

use Drupal\Core\Extension\ExtensionPathResolver;

/**
 * Resolves the active system prompt and exposes its version identifier.
 *
 * Source-of-truth lives in versioned markdown files at
 * `prompts/query_system_prompt.v{N}.md`. Splitting the resolution out of the
 * EventSubscriber means both the runtime override path and the artifact
 * provenance path (which records `prompt_version`) read the same metadata.
 */
class SystemPromptLoader {

  public const DEFAULT_VERSION = 'v3';

  /**
   * The extension path resolver.
   */
  protected ExtensionPathResolver $pathResolver;

  /**
   * Per-instance cache of loaded prompt content keyed by version.
   *
   * @var array<string, string>
   */
  protected array $cache = [];

  /**
   * Transient override of the active version (eval / debugging only).
   */
  protected ?string $override = NULL;

  public function __construct(ExtensionPathResolver $path_resolver) {
    $this->pathResolver = $path_resolver;
  }

  /**
   * Override the active prompt version for the lifetime of this process.
   *
   * Intended for the eval harness and ad-hoc debugging — long-lived requests
   * should not call this. Pass NULL to clear.
   */
  public function setOverride(?string $version): void {
    $this->override = $version === NULL || $version === '' ? NULL : ltrim($version, 'v');
  }

  /**
   * Load the prompt body for a given version, or NULL when the file is missing.
   */
  public function load(string $version = self::DEFAULT_VERSION): ?string {
    if (array_key_exists($version, $this->cache)) {
      return $this->cache[$version];
    }
    $path = $this->resolvePath($version);
    if ($path === NULL || !is_readable($path)) {
      $this->cache[$version] = NULL;
      return NULL;
    }
    $contents = file_get_contents($path);
    $this->cache[$version] = $contents === FALSE ? NULL : trim($contents);
    return $this->cache[$version];
  }

  /**
   * Return the active prompt version identifier.
   *
   * Recorded against every persisted message so eval runs and audit
   * tooling can correlate behavior with prompt changes.
   */
  public function activeVersion(): string {
    if ($this->override !== NULL) {
      return 'v' . $this->override;
    }
    return self::DEFAULT_VERSION;
  }

  /**
   * Resolve the absolute path of a prompt version's markdown file.
   */
  protected function resolvePath(string $version): ?string {
    $version = ltrim($version, 'v');
    try {
      $modulePath = $this->pathResolver->getPath('module', 'dkan_drupal_ai_query');
    }
    catch (\Throwable) {
      return NULL;
    }
    return $modulePath . "/prompts/query_system_prompt.v{$version}.md";
  }

}
