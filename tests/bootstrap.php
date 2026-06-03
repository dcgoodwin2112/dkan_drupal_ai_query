<?php

/**
 * @file
 * Bootstrap for PHPUnit tests.
 *
 * Standalone tests — no Drupal kernel. Loads the site-level Composer autoloader
 * (PHPUnit + getdkan/mock-chain), then registers PSR-4 for our own namespaces
 * plus the dkan_query_tools tool classes the resolver depends on. The query
 * library now ships as a submodule of dkan_mcp_server, so its src/stubs are
 * resolved from there rather than a sibling vendor install.
 */

$siteAutoload = __DIR__ . '/../../../../../vendor/autoload.php';
if (!file_exists($siteAutoload)) {
  fwrite(STDERR, "ERROR: site vendor/autoload.php missing.\n  Run `composer install` at the project root first.\n");
  exit(1);
}
require_once $siteAutoload;

$queryTools = __DIR__ . '/../../dkan_mcp_server/modules/dkan_query_tools';

// PSR-4 for our own namespaces and the dkan_query_tools tool classes the
// resolver depends on (bundled in dkan_mcp_server's submodule).
spl_autoload_register(function ($class) use ($queryTools) {
  $prefixes = [
    'Drupal\\dkan_drupal_ai_query\\' => __DIR__ . '/../src/',
    'Drupal\\dkan_query_tools\\' => $queryTools . '/src/',
    'Drupal\\Tests\\dkan_drupal_ai_query\\' => __DIR__ . '/src/',
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
