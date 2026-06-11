<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\dkan_ai_query\Service\EvalToolCallCollector;
use PHPUnit\Framework\TestCase;

class EvalToolCallCollectorTest extends TestCase {

  public function testRecordsInOrderWithIncrementingIteration(): void {
    $c = new EvalToolCallCollector();
    $c->record('t1', 'list_datasets', [], 100);
    $c->record('t1', 'get_datastore_schema', ['resource_id' => 'abc__v1'], 250);

    $calls = $c->load('t1');
    $this->assertCount(2, $calls);
    $this->assertSame(1, $calls[0]['iteration']);
    $this->assertSame('list_datasets', $calls[0]['tool']);
    $this->assertSame(100, $calls[0]['output_bytes']);
    $this->assertSame(2, $calls[1]['iteration']);
    $this->assertSame(['resource_id' => 'abc__v1'], $calls[1]['input']);
  }

  public function testLoadUnknownThreadReturnsEmpty(): void {
    $c = new EvalToolCallCollector();
    $this->assertSame([], $c->load('missing'));
  }

  public function testForgetClears(): void {
    $c = new EvalToolCallCollector();
    $c->record('t1', 'a', [], 0);
    $c->forget('t1');
    $this->assertSame([], $c->load('t1'));
  }

  public function testThreadsAreIsolated(): void {
    $c = new EvalToolCallCollector();
    $c->record('a', 'tool_a', [], 0);
    $c->record('b', 'tool_b', [], 0);
    $c->record('a', 'tool_a2', [], 0);
    $this->assertCount(2, $c->load('a'));
    $this->assertCount(1, $c->load('b'));
    $this->assertSame(2, $c->load('a')[1]['iteration']);
    $this->assertSame(1, $c->load('b')[0]['iteration']);
  }

  public function testLongStringInputIsTruncated(): void {
    $c = new EvalToolCallCollector();
    $long = str_repeat('x', 500);
    $c->record('t', 'q', ['conditions' => $long], 0);
    $captured = $c->load('t')[0]['input']['conditions'];
    // 200 chars + ellipsis.
    $this->assertSame(201, mb_strlen($captured));
    $this->assertStringEndsWith('…', $captured);
  }

  public function testNonScalarInputIsRedactedToTypeName(): void {
    $c = new EvalToolCallCollector();
    $c->record('t', 'q', ['ctx' => new \stdClass()], 0);
    $this->assertSame('<stdClass>', $c->load('t')[0]['input']['ctx']);
  }

  public function testScalarTypesArePreserved(): void {
    $c = new EvalToolCallCollector();
    $c->record('t', 'q', ['n' => 42, 'b' => TRUE, 'nul' => NULL, 'short' => 'ok'], 0);
    $input = $c->load('t')[0]['input'];
    $this->assertSame(42, $input['n']);
    $this->assertTrue($input['b']);
    $this->assertNull($input['nul']);
    $this->assertSame('ok', $input['short']);
  }

}
