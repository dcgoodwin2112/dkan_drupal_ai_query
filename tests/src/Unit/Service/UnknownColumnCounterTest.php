<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\Service;

use Drupal\dkan_drupal_ai_query\Service\UnknownColumnCounter;
use PHPUnit\Framework\TestCase;

class UnknownColumnCounterTest extends TestCase {

  public function testBumpReturnsRunningCount(): void {
    $c = new UnknownColumnCounter();
    $this->assertSame(1, $c->bump('thread-1'));
    $this->assertSame(2, $c->bump('thread-1'));
    $this->assertSame(3, $c->bump('thread-1'));
  }

  public function testCountReadsWithoutBumping(): void {
    $c = new UnknownColumnCounter();
    $c->bump('thread-1');
    $c->bump('thread-1');
    $this->assertSame(2, $c->count('thread-1'));
    $this->assertSame(2, $c->count('thread-1'));
  }

  public function testForgetResetsThread(): void {
    $c = new UnknownColumnCounter();
    $c->bump('thread-1');
    $c->bump('thread-1');
    $c->forget('thread-1');
    $this->assertSame(0, $c->count('thread-1'));
  }

  public function testThreadsAreIndependent(): void {
    $c = new UnknownColumnCounter();
    $c->bump('a');
    $c->bump('b');
    $c->bump('b');
    $this->assertSame(1, $c->count('a'));
    $this->assertSame(2, $c->count('b'));
  }

  public function testEmptyThreadIdIgnored(): void {
    $c = new UnknownColumnCounter();
    $this->assertSame(0, $c->bump(''));
    $this->assertSame(0, $c->count(''));
  }

  public function testTripThresholdExposed(): void {
    $this->assertSame(3, UnknownColumnCounter::tripThreshold());
  }

}
