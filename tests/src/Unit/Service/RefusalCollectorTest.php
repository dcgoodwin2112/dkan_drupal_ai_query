<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\dkan_ai_query\Service\RefusalCollector;
use PHPUnit\Framework\TestCase;

/**
 * Tests RefusalCollector per-thread refusal storage.
 *
 * @group dkan_ai_query
 */
class RefusalCollectorTest extends TestCase {

  /**
   * Recorded refusals round-trip via get().
   */
  public function testRecordAndGet(): void {
    $c = new RefusalCollector();
    $payload = ['refused' => TRUE, 'reason_category' => 'no_matching_dataset', 'explanation' => 'x'];
    $c->record('thread-1', $payload);
    $this->assertEquals($payload, $c->get('thread-1'));
  }

  /**
   * Get() returns NULL for an unknown thread.
   */
  public function testGetReturnsNullForUnknown(): void {
    $c = new RefusalCollector();
    $this->assertNull($c->get('thread-1'));
  }

  /**
   * Forget() clears a thread's refusal.
   */
  public function testForgetClears(): void {
    $c = new RefusalCollector();
    $c->record('thread-1', ['refused' => TRUE, 'reason_category' => 'other']);
    $c->forget('thread-1');
    $this->assertNull($c->get('thread-1'));
  }

  /**
   * An empty thread id is ignored.
   */
  public function testEmptyThreadIdIgnored(): void {
    $c = new RefusalCollector();
    $c->record('', ['refused' => TRUE]);
    $this->assertNull($c->get(''));
  }

  /**
   * The latest recorded refusal wins.
   */
  public function testLatestRecordWins(): void {
    $c = new RefusalCollector();
    $c->record('t', ['refused' => TRUE, 'reason_category' => 'first']);
    $c->record('t', ['refused' => TRUE, 'reason_category' => 'second']);
    $this->assertSame('second', $c->get('t')['reason_category']);
  }

  /**
   * Refusals are stored independently per thread.
   */
  public function testThreadsAreIndependent(): void {
    $c = new RefusalCollector();
    $c->record('a', ['reason_category' => 'first']);
    $c->record('b', ['reason_category' => 'second']);
    $this->assertSame('first', $c->get('a')['reason_category']);
    $this->assertSame('second', $c->get('b')['reason_category']);
  }

}
