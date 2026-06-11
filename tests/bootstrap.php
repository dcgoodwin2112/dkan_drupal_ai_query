<?php

/**
 * @file
 * Bootstrap for PHPUnit tests.
 *
 * Standalone tests — no Drupal kernel. Loads the site-level Composer autoloader
 * (PHPUnit + getdkan/mock-chain), then registers PSR-4 for our own namespaces
 * plus the dkan_query_tools tool classes the resolver depends on. The query
 * library ships as a submodule of the drupal.org dkan_mcp_server project, so
 * its src/stubs are resolved from wherever that module is installed.
 */

// Resolve the Composer project root. The dev-site layout puts the module at
// <root>/<web>/modules/custom/<module>; on drupal.org GitLab CI the module is
// symlinked into the web tree and __DIR__ resolves to the bare checkout, so
// probe $CI_PROJECT_DIR too; a module-local `composer install` works as well.
$rootCandidates = array_filter([
  dirname(__DIR__, 5),
  getenv('CI_PROJECT_DIR') ?: NULL,
  dirname(__DIR__),
]);
$siteRoot = NULL;
foreach ($rootCandidates as $candidate) {
  if (file_exists($candidate . '/vendor/autoload.php')) {
    $siteRoot = $candidate;
    break;
  }
}
if ($siteRoot === NULL) {
  fwrite(STDERR, "ERROR: vendor/autoload.php not found.\n  Run `composer install` at the project root first.\n");
  exit(1);
}
require_once $siteRoot . '/vendor/autoload.php';

// dkan_query_tools ships inside drupal/dkan_mcp_server — probe the dev-site
// custom checkout first, then composer-installed contrib copies under the
// common web roots.
$queryToolsCandidates = [
  __DIR__ . '/../../dkan_mcp_server/modules/dkan_query_tools',
  __DIR__ . '/../../../contrib/dkan_mcp_server/modules/dkan_query_tools',
  $siteRoot . '/web/modules/contrib/dkan_mcp_server/modules/dkan_query_tools',
  $siteRoot . '/docroot/modules/contrib/dkan_mcp_server/modules/dkan_query_tools',
  $siteRoot . '/web/modules/custom/dkan_mcp_server/modules/dkan_query_tools',
  $siteRoot . '/docroot/modules/custom/dkan_mcp_server/modules/dkan_query_tools',
];
$queryTools = NULL;
foreach ($queryToolsCandidates as $candidate) {
  if (is_dir($candidate)) {
    $queryTools = $candidate;
    break;
  }
}
if ($queryTools === NULL) {
  fwrite(STDERR, "ERROR: dkan_query_tools not found.\n  Install drupal/dkan_mcp_server (custom or contrib) first.\n");
  exit(1);
}

// PSR-4 for our own namespaces and the dkan_query_tools tool classes the
// resolver depends on (bundled in dkan_mcp_server's submodule).
spl_autoload_register(function ($class) use ($queryTools) {
  $prefixes = [
    'Drupal\\dkan_ai_query\\' => __DIR__ . '/../src/',
    'Drupal\\dkan_query_tools\\' => $queryTools . '/src/',
    'Drupal\\Tests\\dkan_ai_query\\' => __DIR__ . '/src/',
  ];
  foreach ($prefixes as $prefix => $base) {
    if (str_starts_with($class, $prefix)) {
      $relative = substr($class, strlen($prefix));
      $path = $base . str_replace('\\', '/', $relative) . '.php';
      if (file_exists($path)) {
        require_once $path;
        return TRUE;
      }
    }
  }
  return FALSE;
});

// Reuse dkan_query_tools' Drupal core stubs (DataResource, MetastoreService,
// DatastoreService, etc.) from the bundled submodule.
$sharedStubDir = $queryTools . '/tests/stubs';
foreach (glob($sharedStubDir . '/*.php') as $stub) {
  require_once $stub;
}

// Local stubs for Drupal classes referenced only from this module's code.
$ownStubDir = __DIR__ . '/stubs';
foreach (glob($ownStubDir . '/*.php') as $stub) {
  require_once $stub;
}
