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

// Resolve the Composer project root. The dev-site layout puts the parent
// module at <root>/<web>/modules/custom/<module>; on drupal.org GitLab CI the
// module is symlinked into the web tree and __DIR__ resolves to the bare
// checkout, so probe $CI_PROJECT_DIR too; a parent-module-local
// `composer install` works as well.
$rootCandidates = array_filter([
  dirname(__DIR__, 7),
  getenv('CI_PROJECT_DIR') ?: NULL,
  dirname(__DIR__, 3),
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

// The drupal/ai module may sit under any of the common web roots (or be
// reachable relative to a dev-site checkout).
$aiSrcCandidates = [
  __DIR__ . '/../../../../../contrib/ai/src/',
  $siteRoot . '/web/modules/contrib/ai/src/',
  $siteRoot . '/docroot/modules/contrib/ai/src/',
];
$aiSrc = NULL;
foreach ($aiSrcCandidates as $candidate) {
  if (is_dir($candidate)) {
    $aiSrc = $candidate;
    break;
  }
}
if ($aiSrc === NULL) {
  fwrite(STDERR, "ERROR: drupal/ai module not found.\n  Install drupal/ai first.\n");
  exit(1);
}

spl_autoload_register(function ($class) use ($aiSrc) {
  $prefixes = [
    'Drupal\\dkan_ai_query_mock\\' => __DIR__ . '/../src/',
    'Drupal\\Tests\\dkan_ai_query_mock\\' => __DIR__ . '/src/',
    'Drupal\\ai\\' => $aiSrc,
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
