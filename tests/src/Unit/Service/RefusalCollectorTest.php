<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\dkan_ai_query\Service\RefusalCollector;
use PHPUnit\Framework\TestCase;

class RefusalCollectorTest extends TestCase {

  public function testRecordAndGet(): void {
    $c = new RefusalCollector();
    $payload = ['refused' => TRUE, 'reason_category' => 'no_matching_dataset', 'explanation' => 'x'];
    $c->record('thread-1', $payload);
    $this->assertEquals($payload, $c->get('thread-1'));
  }

  public function testGetReturnsNullForUnknown(): void {
    $c = new RefusalCollector();
    $this->assertNull($c->get('thread-1'));
  }

  public function testForgetClears(): void {
    $c = new RefusalCollector();
    $c->record('thread-1', ['refused' => TRUE, 'reason_category' => 'other']);
    $c->forget('thread-1');
    $this->assertNull($c->get('thread-1'));
  }

  public function testEmptyThreadIdIgnored(): void {
    $c = new RefusalCollector();
    $c->record('', ['refused' => TRUE]);
    $this->assertNull($c->get(''));
  }

  public function testLatestRecordWins(): void {
    $c = new RefusalCollector();
    $c->record('t', ['refused' => TRUE, 'reason_category' => 'first']);
    $c->record('t', ['refused' => TRUE, 'reason_category' => 'second']);
    $this->assertSame('second', $c->get('t')['reason_category']);
  }

  public function testThreadsAreIndependent(): void {
    $c = new RefusalCollector();
    $c->record('a', ['reason_category' => 'first']);
    $c->record('b', ['reason_category' => 'second']);
    $this->assertSame('first', $c->get('a')['reason_category']);
    $this->assertSame('second', $c->get('b')['reason_category']);
  }

}
