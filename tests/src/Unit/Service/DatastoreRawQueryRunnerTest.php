<?php

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\Service;

use Drupal\datastore\Service\DatastoreQuery;
use Drupal\datastore\Service\Query;
use Drupal\dkan_drupal_ai_query\Service\DatastoreRawQueryRunner;
use Drupal\dkan_drupal_ai_query\Service\ResourceIdResolver;
use Opis\JsonSchema\ValidationError;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use RootedData\Exception\ValidationException;
use RootedData\RootedJsonData;

/**
 * @covers \Drupal\dkan_drupal_ai_query\Service\DatastoreRawQueryRunner
 */
class DatastoreRawQueryRunnerTest extends TestCase {

  protected function buildResolver(array $resolutions): ResourceIdResolver {
    $resolver = $this->createMock(ResourceIdResolver::class);
    $resolver->method('resolve')->willReturnCallback(
      static fn(string $input): ?string => $resolutions[$input] ?? NULL
    );
    return $resolver;
  }

  protected function buildQuery(string $resultJson = '{"results":[{"a":1}],"count":1}'): Query {
    $query = $this->createMock(Query::class);
    $query->method('runQuery')->willReturn(new RootedJsonData($resultJson));
    return $query;
  }

  public function testEmptyPayloadReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run('   ');
    $this->assertSame(['error' => 'payload is required.'], $out);
  }

  public function testInvalidJsonReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run('{not json');
    $this->assertArrayHasKey('error', $out);
    $this->assertStringStartsWith('Invalid JSON', $out['error']);
  }

  public function testNonObjectPayloadReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $this->assertSame(['error' => 'Payload must be a JSON object.'], $runner->run('[]'));
    $this->assertSame(['error' => 'Payload must be a JSON object.'], $runner->run('"a"'));
  }

  public function testValidPayloadReturnsRunQueryResult(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery('{"results":[{"x":1}],"count":1}'),
      $this->buildResolver(['abc__1' => 'abc__1']),
    );
    $out = $runner->run(json_encode([
      'resources' => [['id' => 'abc__1', 'alias' => 't']],
      'limit' => 5,
    ]));
    $this->assertSame([['x' => 1]], $out['results']);
    $this->assertSame(1, $out['count']);
  }

  public function testFuzzyResourceIdIsResolvedInPlace(): void {
    $captured = NULL;
    $factory = function (string $json) use (&$captured): DatastoreQuery {
      $captured = $json;
      return new DatastoreQuery($json);
    };
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver(['Some Title' => 'real__123']),
      $factory,
    );
    $runner->run(json_encode([
      'resources' => [['id' => 'Some Title']],
    ]));
    $this->assertNotNull($captured);
    $decoded = json_decode($captured, TRUE);
    $this->assertSame('real__123', $decoded['resources'][0]['id']);
    // Default alias is filled in when missing.
    $this->assertSame('t', $decoded['resources'][0]['alias']);
  }

  public function testUnresolvableResourceReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run(json_encode([
      'resources' => [['id' => 'Nonexistent']],
    ]));
    $this->assertArrayHasKey('error', $out);
    $this->assertStringContainsString('Could not resolve resource', $out['error']);
  }

  public function testResourcesMustBeListOfObjects(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run(json_encode(['resources' => 'not-a-list']));
    $this->assertSame(['error' => 'resources must be an array of {id, alias} objects.'], $out);
  }

  public function testResourceWithoutIdReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run(json_encode(['resources' => [['alias' => 't']]]));
    $this->assertArrayHasKey('error', $out);
    $this->assertStringContainsString('resources[0]', $out['error']);
  }

  public function testValidationErrorFormatsValidationErrors(): void {
    $error = new ValidationError(
      'bad',
      ['limit'],
      [],
      (object) [],
      'maximum',
      ['max' => 500],
    );
    $result = (new ValidationResult(5))->addError($error);
    $factory = function () use ($result): DatastoreQuery {
      throw new ValidationException('JSON Schema validation failed.', $result);
    };
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver(['abc__1' => 'abc__1']),
      $factory,
    );
    $out = $runner->run(json_encode([
      'resources' => [['id' => 'abc__1', 'alias' => 't']],
      'limit' => 999999,
    ]));
    $this->assertSame('JSON Schema validation failed.', $out['error']);
    $this->assertCount(1, $out['validation_errors']);
    $this->assertSame('/limit', $out['validation_errors'][0]['pointer']);
    $this->assertSame('maximum', $out['validation_errors'][0]['keyword']);
    $this->assertSame(['max' => 500], $out['validation_errors'][0]['args']);
  }

  public function testRunQueryThrowingReturnsError(): void {
    $query = $this->createMock(Query::class);
    $query->method('runQuery')->willThrowException(new \RuntimeException('storage missing'));
    $runner = new DatastoreRawQueryRunner(
      $query,
      $this->buildResolver(['abc__1' => 'abc__1']),
    );
    $out = $runner->run(json_encode([
      'resources' => [['id' => 'abc__1', 'alias' => 't']],
    ]));
    $this->assertSame('storage missing', $out['error']);
  }

}
