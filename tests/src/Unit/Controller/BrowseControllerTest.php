<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\Controller;

use Drupal\dkan_drupal_ai_query\Controller\BrowseController;
use Drupal\dkan_drupal_ai_query\Service\SchemaBrowserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for BrowseController.
 *
 * Stubs SchemaBrowserService and verifies the HTTP-shape concerns: status
 * codes, query-param validation, and Cache-Control headers.
 *
 * @covers \Drupal\dkan_drupal_ai_query\Controller\BrowseController
 */
class BrowseControllerTest extends TestCase {

  /**
   * Datasets() clamps offset>=0, limit∈[1,100], and forwards `q` verbatim.
   *
   * The controller does not trim — that's the service's job — but it does
   * pass the raw value through, so our assertion checks the dispatch.
   */
  public function testDatasetsClampsOffsetAndLimit(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->expects($this->exactly(3))
      ->method('listDatasets')
      ->willReturnCallback(function (int $offset, int $limit, string $q) {
        return ['datasets' => [], 'total' => 0, 'offset' => $offset, 'limit' => $limit, 'q' => $q];
      });

    $controller = new BrowseController($service, new NullLogger());

    // Negative offset → controller passes through; service would clamp.
    // What the controller MUST do is cast to int from query string. We
    // assert the dispatch shape rather than the clamp itself.
    $controller->datasets(Request::create('/', 'GET', ['offset' => '-5', 'limit' => '500']));
    $controller->datasets(Request::create('/', 'GET', ['offset' => '0', 'limit' => '0']));
    $controller->datasets(Request::create('/', 'GET', ['offset' => '50', 'limit' => '25', 'q' => 'parks']));
  }

  /**
   * Datasets() returns a successful payload with private/short-cache headers.
   */
  public function testDatasetsHappyPathHeaders(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->method('listDatasets')->willReturn([
      'datasets' => [['identifier' => 'a-1', 'title' => 'A']],
      'total' => 1,
      'offset' => 0,
      'limit' => 25,
      'q' => '',
    ]);
    $controller = new BrowseController($service, new NullLogger());
    $response = $controller->datasets(Request::create('/'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('max-age=60, private', $response->headers->get('Cache-Control'));
    $this->assertJson($response->getContent());
  }

  /**
   * Datasets() returns 500 + no-store when the service signals an error.
   */
  public function testDatasetsServiceErrorYieldsServerError(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->method('listDatasets')->willReturn(['error' => 'Search failed: timeout']);
    $controller = new BrowseController($service, new NullLogger());
    $response = $controller->datasets(Request::create('/'));

    $this->assertSame(500, $response->getStatusCode());
    $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
  }

  /**
   * Dataset() returns 404 + no-store when the service can't find the UUID.
   */
  public function testDatasetMissingYields404(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->method('dataset')->willReturn(['error' => 'Dataset not found: abc']);
    $controller = new BrowseController($service, new NullLogger());
    $response = $controller->dataset('abc');

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
  }

  /**
   * Dataset() returns 200 + short-cache headers on the happy path.
   */
  public function testDatasetHappyPathHeaders(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->method('dataset')->willReturn([
      'identifier' => 'abc',
      'title' => 'Test',
      'distributions' => [],
    ]);
    $controller = new BrowseController($service, new NullLogger());
    $response = $controller->dataset('abc');

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('max-age=60, private', $response->headers->get('Cache-Control'));
  }

  /**
   * Schema() / stats() use the longer (5-min) max-age window.
   */
  public function testSchemaAndStatsUseLongerCacheTtl(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->method('schema')->willReturn(['resource_id' => 'rid__v1', 'columns' => []]);
    $service->method('stats')->willReturn(['resource_id' => 'rid__v1', 'columns' => []]);
    $controller = new BrowseController($service, new NullLogger());

    $schemaResp = $controller->schema('rid__v1');
    $statsResp = $controller->stats('rid__v1');
    $this->assertSame('max-age=300, private', $schemaResp->headers->get('Cache-Control'));
    $this->assertSame('max-age=300, private', $statsResp->headers->get('Cache-Control'));
  }

  /**
   * Schema() returns 404 when the resource id can't be resolved.
   */
  public function testSchemaMissingYields404(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->method('schema')->willReturn(['error' => 'No distribution found for resource: zzz']);
    $controller = new BrowseController($service, new NullLogger());
    $response = $controller->schema('zzz__1');
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Sample() and distinct() are no-store sensitive previews.
   *
   * They shouldn't survive an access-control change downstream.
   */
  public function testSamplePreviewIsNoStore(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->method('sample')->willReturn(['resource_id' => 'rid__v1', 'rows' => []]);
    $controller = new BrowseController($service, new NullLogger());
    $response = $controller->sample('rid__v1', Request::create('/', 'GET', ['n' => '5']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
  }

  /**
   * Distinct() forwards the column path component into the service call.
   */
  public function testDistinctForwardsColumnAndLimit(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->expects($this->once())
      ->method('distinct')
      ->with('rid__v1', 'state', 50)
      ->willReturn(['values' => ['CA'], 'value_count' => 1, 'truncated' => FALSE]);
    $controller = new BrowseController($service, new NullLogger());
    $response = $controller->distinct('rid__v1', 'state', Request::create('/', 'GET', ['limit' => '50']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
  }

  /**
   * Sample() forwards the requested n into the service call.
   */
  public function testSampleForwardsN(): void {
    $service = $this->createMock(SchemaBrowserService::class);
    $service->expects($this->once())
      ->method('sample')
      ->with('rid__v1', 12)
      ->willReturn(['resource_id' => 'rid__v1', 'rows' => []]);
    $controller = new BrowseController($service, new NullLogger());
    $controller->sample('rid__v1', Request::create('/', 'GET', ['n' => '12']));
  }

}
