<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\dkan_drupal_ai_query\EventSubscriber\ToolCallEvalCollectorSubscriber;
use Drupal\dkan_drupal_ai_query\Service\EvalToolCallCollector;
use PHPUnit\Framework\TestCase;

class ToolCallEvalCollectorSubscriberTest extends TestCase {

  protected EvalToolCallCollector $collector;

  protected ToolCallEvalCollectorSubscriber $subscriber;

  protected function setUp(): void {
    $this->collector = new EvalToolCallCollector();
    $this->subscriber = new ToolCallEvalCollectorSubscriber($this->collector);
  }

  public function testRecordsToolNameInputAndOutputBytes(): void {
    $tool = new \FunctionCallStub(
      'get_datastore_schema',
      str_repeat('x', 1234),
      ['resource_id' => 'abc__v1'],
    );
    $event = new AgentToolFinishedExecutionEvent('thread-1', $tool);
    $this->subscriber->onToolFinished($event);

    $calls = $this->collector->load('thread-1');
    $this->assertCount(1, $calls);
    $this->assertSame('get_datastore_schema', $calls[0]['tool']);
    $this->assertSame(['resource_id' => 'abc__v1'], $calls[0]['input']);
    $this->assertSame(1234, $calls[0]['output_bytes']);
    $this->assertSame(1, $calls[0]['iteration']);
  }

  public function testFallsBackToRunnerIdWhenThreadIdEmpty(): void {
    $tool = new \FunctionCallStub('list_datasets', 'ok', []);
    // CLI eval runs leave threadId empty; runnerId carries the thread.
    $event = new AgentToolFinishedExecutionEvent('', $tool, 'eval-runner-1');
    $this->subscriber->onToolFinished($event);

    $this->assertCount(1, $this->collector->load('eval-runner-1'));
  }

  public function testIgnoresEventWithNoThreadOrRunner(): void {
    $tool = new \FunctionCallStub('list_datasets', 'ok', []);
    $event = new AgentToolFinishedExecutionEvent('', $tool, '');
    $this->subscriber->onToolFinished($event);

    $this->assertSame([], $this->collector->load(''));
  }

  public function testMultipleCallsAccumulate(): void {
    foreach (['list_datasets', 'list_distributions', 'get_datastore_schema'] as $name) {
      $tool = new \FunctionCallStub($name, 'ok', []);
      $event = new AgentToolFinishedExecutionEvent('thread-1', $tool);
      $this->subscriber->onToolFinished($event);
    }
    $calls = $this->collector->load('thread-1');
    $this->assertSame([1, 2, 3], array_column($calls, 'iteration'));
    $this->assertSame(['list_datasets', 'list_distributions', 'get_datastore_schema'], array_column($calls, 'tool'));
  }

  public function testSubscribedEventName(): void {
    $events = ToolCallEvalCollectorSubscriber::getSubscribedEvents();
    $this->assertArrayHasKey(AgentToolFinishedExecutionEvent::EVENT_NAME, $events);
  }

}
