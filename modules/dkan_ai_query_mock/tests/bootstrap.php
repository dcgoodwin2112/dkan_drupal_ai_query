<?php

/**
 * @file
 * Bootstrap for dkan_ai_query_mock unit tests.
 *
 * Loads the site-level Composer autoloader for PHPUnit + getdkan/mock-chain.
 * PSR-4 registers the submodule's namespace plus the parent module and the AI
 * module's chat-types subtree so ChatInput/ChatMessage/ToolsFunctionOutput
 * can be exercised without a full Drupal kernel.
 */

$siteAutoload = __DIR__ . '/../../../../../../../vendor/autoload.php';
if (!file_exists($siteAutoload)) {
  fwrite(STDERR, "ERROR: site vendor/autoload.php missing.\n  Run `composer install` at the project root first.\n");
  exit(1);
}
require_once $siteAutoload;

spl_autoload_register(function ($class) {
  $prefixes = [
    'Drupal\\dkan_ai_query_mock\\' => __DIR__ . '/../src/',
    'Drupal\\Tests\\dkan_ai_query_mock\\' => __DIR__ . '/src/',
    'Drupal\\ai\\' => __DIR__ . '/../../../../../contrib/ai/src/',
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

$ownStubDir = __DIR__ . '/stubs';
if (is_dir($ownStubDir)) {
  foreach (glob($ownStubDir . '/*.php') as $stub) {
    require_once $stub;
  }
}
