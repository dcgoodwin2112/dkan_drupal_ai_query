<?php

namespace Drupal\Core\Extension;

/**
 * Minimal stub for Drupal\Core\Extension\ExtensionPathResolver.
 */
class ExtensionPathResolver {

  /**
   * Stub that returns an empty path; tests override via createMock instead.
   */
  public function getPath(string $type, string $name): string {
    return '';
  }

}
