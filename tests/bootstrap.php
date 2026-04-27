<?php

/**
 * @file
 * Bootstrap for PHPUnit tests.
 *
 * Standalone tests — no Drupal kernel. Borrows PHPUnit + getdkan/mock-chain
 * from the sibling dkan_query_tools/vendor since we share its test pattern
 * and don't want a duplicate composer install here.
 */

$qtAutoload = __DIR__ . '/../../dkan_query_tools/vendor/autoload.php';
if (!file_exists($qtAutoload)) {
  fwrite(STDERR, "ERROR: dkan_query_tools/vendor/autoload.php missing.\n  Run `composer install` in web/modules/custom/dkan_query_tools first.\n");
  exit(1);
}
require_once $qtAutoload;

// PSR-4 for our own namespaces and the sibling tool classes the resolver
// depends on. dkan_query_tools/composer.json owns its own PSR-4 mapping; we
// register a parallel one so its classes are reachable without re-installing.
spl_autoload_register(function ($class) {
  $prefixes = [
    'Drupal\\dkan_drupal_ai_query\\' => __DIR__ . '/../src/',
    'Drupal\\dkan_query_tools\\' => __DIR__ . '/../../dkan_query_tools/src/',
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
// DatastoreService, etc.).
$sharedStubDir = __DIR__ . '/../../dkan_query_tools/tests/stubs';
foreach (glob($sharedStubDir . '/*.php') as $stub) {
  require_once $stub;
}

// Local stubs for Drupal classes referenced only from this module's code.
$ownStubDir = __DIR__ . '/stubs';
foreach (glob($ownStubDir . '/*.php') as $stub) {
  require_once $stub;
}
