<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\dkan_drupal_ai_query\EventSubscriber\UnknownColumnGuardSubscriber;
use Drupal\dkan_drupal_ai_query\Service\RefusalCollector;
use Drupal\dkan_drupal_ai_query\Service\UnknownColumnCounter;
use PHPUnit\Framework\TestCase;

class UnknownColumnGuardSubscriberTest extends TestCase {

  protected UnknownColumnCounter $counter;

  protected RefusalCollector $refusals;

  protected UnknownColumnGuardSubscriber $subscriber;

  protected function setUp(): void {
    $this->counter = new UnknownColumnCounter();
    $this->refusals = new RefusalCollector();
    $this->subscriber = new UnknownColumnGuardSubscriber($this->counter, $this->refusals);
  }

  protected function unknownColumnTool(string $col = 'rate_per_100k', array $available = ['state', 'year']): \FunctionCallStub {
    return new \FunctionCallStub(
      'query_datastore',
      json_encode([
        'error' => 'unknown_column',
        'column' => $col,
        'available_columns' => $available,
        'resource_id' => 'rid__1',
      ]),
      [],
    );
  }

  public function testFirstTwoUnknownColumnsAreNotRefused(): void {
    foreach ([1, 2] as $i) {
      $tool = $this->unknownColumnTool();
      $event = new AgentToolFinishedExecutionEvent('thread-1', $tool);
      $this->subscriber->onToolFinished($event);
      $decoded = json_decode($tool->getReadableOutput(), TRUE);
      $this->assertSame('unknown_column', $decoded['error'], "Hit $i should pass through");
    }
    $this->assertNull($this->refusals->get('thread-1'));
  }

  public function testThirdUnknownColumnRewritesOutputAndRecordsRefusal(): void {
    foreach ([1, 2, 3] as $i) {
      $tool = $this->unknownColumnTool('foo' . $i);
      $event = new AgentToolFinishedExecutionEvent('thread-1', $tool);
      $this->subscriber->onToolFinished($event);
    }
    // The 3rd tool's output should now be a refusal payload.
    $decoded = json_decode($tool->getReadableOutput(), TRUE);
    $this->assertTrue($decoded['refused']);
    $this->assertSame('repeated_unknown_column', $decoded['reason_category']);
    $this->assertStringContainsString('foo3', $decoded['explanation']);

    // RefusalCollector should also reflect it so eval scoring picks it up.
    $recorded = $this->refusals->get('thread-1');
    $this->assertSame('repeated_unknown_column', $recorded['reason_category']);
  }

  public function testIgnoresNonGuardedTools(): void {
    $tool = new \FunctionCallStub(
      'sample_rows',
      json_encode(['error' => 'unknown_column', 'column' => 'x', 'resource_id' => 'rid__1']),
      [],
    );
    $event = new AgentToolFinishedExecutionEvent('thread-1', $tool);
    $this->subscriber->onToolFinished($event);
    $this->assertSame(0, $this->counter->count('thread-1'));
  }

  public function testIgnoresSuccessfulQueryResults(): void {
    $tool = new \FunctionCallStub(
      'query_datastore',
      json_encode(['results' => [['x' => 1]], 'total_rows' => 1]),
      [],
    );
    $event = new AgentToolFinishedExecutionEvent('thread-1', $tool);
    $this->subscriber->onToolFinished($event);
    $this->assertSame(0, $this->counter->count('thread-1'));
  }

  public function testFallsBackToRunnerIdWhenThreadIdEmpty(): void {
    $tool = $this->unknownColumnTool();
    // ThreadId empty (CLI eval), runner id provided.
    $event = new AgentToolFinishedExecutionEvent('', $tool, 'runner-7');
    $this->subscriber->onToolFinished($event);
    $this->assertSame(1, $this->counter->count('runner-7'));
  }

  public function testThreadsCountIndependently(): void {
    foreach (['a', 'a', 'b'] as $tid) {
      $tool = $this->unknownColumnTool();
      $event = new AgentToolFinishedExecutionEvent($tid, $tool);
      $this->subscriber->onToolFinished($event);
    }
    $this->assertSame(2, $this->counter->count('a'));
    $this->assertSame(1, $this->counter->count('b'));
  }

}
