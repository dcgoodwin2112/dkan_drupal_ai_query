<?php

namespace Drupal\Tests\dkan_ai_query\Unit\Service;

use Drupal\dkan_datastore\Service\DatastoreQuery;
use Drupal\dkan_datastore\Service\Query;
use Drupal\dkan_ai_query\Service\DatastoreRawQueryRunner;
use Drupal\dkan_ai_query\Service\ResourceIdResolver;
use Opis\JsonSchema\ValidationError;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use RootedData\Exception\ValidationException;
use RootedData\RootedJsonData;

/**
 * Tests DatastoreRawQueryRunner payload validation and error shaping.
 *
 * @covers \Drupal\dkan_ai_query\Service\DatastoreRawQueryRunner
 * @group dkan_ai_query
 */
class DatastoreRawQueryRunnerTest extends TestCase {

  /**
   * Builds a ResourceIdResolver mock from a resolution map.
   */
  protected function buildResolver(array $resolutions): ResourceIdResolver {
    $resolver = $this->createMock(ResourceIdResolver::class);
    $resolver->method('resolve')->willReturnCallback(
      static fn(string $input): ?string => $resolutions[$input] ?? NULL
    );
    return $resolver;
  }

  /**
   * Builds a Query mock returning the given result JSON.
   */
  protected function buildQuery(string $resultJson = '{"results":[{"a":1}],"count":1}'): Query {
    $query = $this->createMock(Query::class);
    $query->method('runQuery')->willReturn(new RootedJsonData($resultJson));
    return $query;
  }

  /**
   * A blank payload returns an error.
   */
  public function testEmptyPayloadReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run('   ');
    $this->assertSame(['error' => 'payload is required.'], $out);
  }

  /**
   * Malformed JSON returns an Invalid JSON error.
   */
  public function testInvalidJsonReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run('{not json');
    $this->assertArrayHasKey('error', $out);
    $this->assertStringStartsWith('Invalid JSON', $out['error']);
  }

  /**
   * Non-object JSON payloads are rejected.
   */
  public function testNonObjectPayloadReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $this->assertSame(['error' => 'Payload must be a JSON object.'], $runner->run('[]'));
    $this->assertSame(['error' => 'Payload must be a JSON object.'], $runner->run('"a"'));
  }

  /**
   * A valid payload returns the runQuery() result.
   */
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

  /**
   * Fuzzy resource ids are resolved in place before querying.
   */
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

  /**
   * An unresolvable resource id returns an error.
   */
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

  /**
   * Resources must be a list of objects.
   */
  public function testResourcesMustBeListOfObjects(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run(json_encode(['resources' => 'not-a-list']));
    $this->assertSame(['error' => 'resources must be an array of {id, alias} objects.'], $out);
  }

  /**
   * A resource entry without an id returns an error.
   */
  public function testResourceWithoutIdReturnsError(): void {
    $runner = new DatastoreRawQueryRunner(
      $this->buildQuery(),
      $this->buildResolver([]),
    );
    $out = $runner->run(json_encode(['resources' => [['alias' => 't']]]));
    $this->assertArrayHasKey('error', $out);
    $this->assertStringContainsString('resources[0]', $out['error']);
  }

  /**
   * Schema validation failures are formatted as validation_errors.
   */
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

  /**
   * Exceptions from runQuery() surface as error payloads.
   */
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
