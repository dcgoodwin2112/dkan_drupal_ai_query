<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\dkan_ai_query\Service\UnknownColumnCounter;
use PHPUnit\Framework\TestCase;

/**
 * Tests UnknownColumnCounter per-thread counting.
 *
 * @group dkan_ai_query
 */
class UnknownColumnCounterTest extends TestCase {

  /**
   * Bump() returns the running count for a thread.
   */
  public function testBumpReturnsRunningCount(): void {
    $c = new UnknownColumnCounter();
    $this->assertSame(1, $c->bump('thread-1'));
    $this->assertSame(2, $c->bump('thread-1'));
    $this->assertSame(3, $c->bump('thread-1'));
  }

  /**
   * Count() reads the tally without incrementing it.
   */
  public function testCountReadsWithoutBumping(): void {
    $c = new UnknownColumnCounter();
    $c->bump('thread-1');
    $c->bump('thread-1');
    $this->assertSame(2, $c->count('thread-1'));
    $this->assertSame(2, $c->count('thread-1'));
  }

  /**
   * Forget() resets a thread's tally to zero.
   */
  public function testForgetResetsThread(): void {
    $c = new UnknownColumnCounter();
    $c->bump('thread-1');
    $c->bump('thread-1');
    $c->forget('thread-1');
    $this->assertSame(0, $c->count('thread-1'));
  }

  /**
   * Counts are tracked independently per thread.
   */
  public function testThreadsAreIndependent(): void {
    $c = new UnknownColumnCounter();
    $c->bump('a');
    $c->bump('b');
    $c->bump('b');
    $this->assertSame(1, $c->count('a'));
    $this->assertSame(2, $c->count('b'));
  }

  /**
   * An empty thread id is ignored.
   */
  public function testEmptyThreadIdIgnored(): void {
    $c = new UnknownColumnCounter();
    $this->assertSame(0, $c->bump(''));
    $this->assertSame(0, $c->count(''));
  }

  /**
   * TripThreshold() exposes the guard threshold.
   */
  public function testTripThresholdExposed(): void {
    $this->assertSame(3, UnknownColumnCounter::tripThreshold());
  }

}
