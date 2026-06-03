<?php

namespace Drupal\dkan_common;

/**
 * Stub for Drupal\dkan_common\DataResource.
 *
 * Implements only the helper used by ArtifactCaptureSubscriber.
 */
class DataResource {

  /**
   * Split a "{identifier}__{version}" string into [identifier, version].
   */
  public static function getIdentifierAndVersion(string $resourceId): array {
    if (str_contains($resourceId, '__')) {
      [$id, $version] = explode('__', $resourceId, 2);
      return [$id, $version];
    }
    return [$resourceId, NULL];
  }

}
