<?php

/**
 * @file
 * Bootstrap for dkan_drupal_ai_query_mock unit tests.
 *
 * Reuses dkan_query_tools' vendor for PHPUnit + getdkan/mock-chain. PSR-4
 * registers the submodule's namespace plus the parent module and the AI
 * module's chat-types subtree so ChatInput/ChatMessage/ToolsFunctionOutput
 * can be exercised without a full Drupal kernel.
 */

$qtAutoload = __DIR__ . '/../../../../dkan_query_tools/vendor/autoload.php';
if (!file_exists($qtAutoload)) {
  fwrite(STDERR, "ERROR: dkan_query_tools/vendor/autoload.php missing.\n  Run `composer install` in web/modules/custom/dkan_query_tools first.\n");
  exit(1);
}
require_once $qtAutoload;

spl_autoload_register(function ($class) {
  $prefixes = [
    'Drupal\\dkan_drupal_ai_query_mock\\' => __DIR__ . '/../src/',
    'Drupal\\Tests\\dkan_drupal_ai_query_mock\\' => __DIR__ . '/src/',
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
