<?php

declare(strict_types=1);

namespace Drupal\dkan_ai_query_mock\Commands;

use Drupal\dkan_ai_query_mock\Service\FixtureLoader;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the National Parks fixture dataset.
 */
class FixtureCommands extends DrushCommands {

  public function __construct(
    private readonly FixtureLoader $fixtureLoader,
  ) {
    parent::__construct();
  }

  /**
   * Install the National Parks fixture dataset.
   *
   * Registers the harvest plan, runs it, and drains the localize / datastore /
   * post-import queues synchronously. Idempotent; safe to re-run.
   *
   * @command dkan-aiq-mock:fixture:install
   */
  public function install(): void {
    $this->logger()->notice('Installing National Parks fixture...');
    $resourceId = $this->fixtureLoader->install();
    if ($resourceId === NULL) {
      $this->logger()->warning(
        'Fixture installed but resource_id could not be resolved. Check that all queues drained without errors.'
      );
      return;
    }
    $this->logger()->success(sprintf('National Parks fixture installed. resource_id: %s', $resourceId));
  }

  /**
   * Remove the National Parks fixture dataset.
   *
   * Reverts the harvest, deregisters the plan, and drops the datastore table.
   *
   * @command dkan-aiq-mock:fixture:remove
   */
  public function remove(): void {
    $this->logger()->notice('Removing National Parks fixture...');
    $this->fixtureLoader->remove();
    $this->logger()->success('National Parks fixture removed.');
  }

  /**
   * Show installation status of the National Parks fixture.
   *
   * @command dkan-aiq-mock:fixture:status
   */
  public function status(): void {
    if (!$this->fixtureLoader->isInstalled()) {
      $this->logger()->notice('Not installed. Run `drush dkan-aiq-mock:fixture:install` to seed.');
      return;
    }
    $resourceId = $this->fixtureLoader->resolveResourceId();
    if ($resourceId === NULL) {
      $this->logger()->warning(
        'Harvest plan registered but resource_id is unresolved (queues not yet drained, or import failed).'
      );
      return;
    }
    $this->logger()->success(sprintf('Installed. resource_id: %s', $resourceId));
  }

}
