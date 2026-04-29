<?php

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\datastore\DatastoreService;
use Drupal\dkan_drupal_ai_query\EventSubscriber\ArtifactCaptureSubscriber;
use Drupal\dkan_drupal_ai_query\Service\ArtifactStorage;
use Drupal\dkan_drupal_ai_query\Service\RefusalCollector;
use Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArtifactCaptureSubscriberTest extends TestCase {

  protected function buildSubscriber(
    ArtifactStorage $artifacts,
    ?ResourceIdResolver $resolver = NULL,
    ?RefusalCollector $refusals = NULL,
  ): ArtifactCaptureSubscriber {
    $resolver = $resolver ?? $this->createMock(ResourceIdResolver::class);
    return new ArtifactCaptureSubscriber(
      $artifacts,
      $this->createMock(LoggerInterface::class),
      $resolver,
      $this->createMock(DatastoreService::class),
      $refusals ?? new RefusalCollector(),
    );
  }

  /**
   * Regression for finding #1.
   *
   * DatastoreTools::queryDatastore returns total_rows; the captured artifact
   * must propagate that into its `count` field so the UI can render the true
   * total. If this test fails, the table summary will fall back to row-count.
   */
  public function testCaptureDataReadsTotalRows(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $artifacts->expects($this->once())
      ->method('append')
      ->willReturnCallback(function (string $tid, array $entry) use (&$captured) {
        $captured = $entry;
      });

    $resolver = $this->createMock(ResourceIdResolver::class);
    $resolver->method('resolve')->willReturnArgument(0);
    $resolver->method('resolveDistributionUuid')->willReturn(NULL);

    $subscriber = $this->buildSubscriber($artifacts, $resolver);

    $tool = new \FunctionCallStub(
      'query_datastore',
      json_encode([
        'results' => [['a' => 1], ['a' => 2], ['a' => 3]],
        'total_rows' => 1234,
      ]),
      ['resource_id' => 'rid__1', 'limit' => 3],
    );

    $event = new AgentToolFinishedExecutionEvent('thread-x', $tool);
    $subscriber->onToolFinished($event);

    $this->assertSame('data', $captured['type']);
    $this->assertSame('query_datastore', $captured['tool']);
    $this->assertCount(3, $captured['rows']);
    $this->assertSame(1234, $captured['count'], 'Artifact count must reflect total_rows from queryDatastore');
  }

  public function testCaptureDataIgnoresErrorOutput(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $artifacts->expects($this->never())->method('append');

    $tool = new \FunctionCallStub(
      'query_datastore',
      json_encode(['error' => 'Could not resolve resource: foo']),
      ['resource_id' => 'foo'],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));
  }

  public function testCaptureChartParsesJsonString(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $captured = NULL;
    $artifacts->expects($this->once())
      ->method('append')
      ->willReturnCallback(function (string $tid, array $entry) use (&$captured) {
        $captured = $entry;
      });

    $spec = [
      '$schema' => 'https://vega.github.io/schema/vega-lite/v5.json',
      'data' => ['values' => [['x' => 'A', 'y' => 1]]],
      'mark' => 'bar',
    ];
    $tool = new \FunctionCallStub(
      'create_chart',
      json_encode(['status' => 'chart_rendered']),
      ['spec' => json_encode($spec)],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));

    $this->assertSame('chart', $captured['type']);
    $this->assertSame('bar', $captured['spec']['mark']);
  }

  public function testNoCaptureForUnrelatedTools(): void {
    $artifacts = $this->createMock(ArtifactStorage::class);
    $artifacts->expects($this->never())->method('append');

    $tool = new \FunctionCallStub(
      'list_datasets',
      json_encode(['datasets' => []]),
      [],
    );

    $subscriber = $this->buildSubscriber($artifacts);
    $subscriber->onToolFinished(new AgentToolFinishedExecutionEvent('thread-x', $tool));
  }

}
