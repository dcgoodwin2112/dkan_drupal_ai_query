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

$siteAutoload = __DIR__ . '/../../../../../vendor/autoload.php';
if (!file_exists($siteAutoload)) {
  fwrite(STDERR, "ERROR: site vendor/autoload.php missing.\n  Run `composer install` at the project root first.\n");
  exit(1);
}
require_once $siteAutoload;

// dkan_query_tools ships inside drupal/dkan_mcp_server — probe the dev-site
// custom checkout first, then a composer-installed contrib copy.
$queryToolsCandidates = [
  __DIR__ . '/../../dkan_mcp_server/modules/dkan_query_tools',
  __DIR__ . '/../../../contrib/dkan_mcp_server/modules/dkan_query_tools',
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
