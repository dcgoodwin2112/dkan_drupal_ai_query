<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query\Unit\Service;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\dkan_drupal_ai_query\Service\SystemPromptLoader;
use PHPUnit\Framework\TestCase;

class SystemPromptLoaderTest extends TestCase {

  protected string $tmpDir;

  protected function setUp(): void {
    $this->tmpDir = sys_get_temp_dir() . '/prompts_' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir);
    mkdir($this->tmpDir . '/prompts');
  }

  protected function tearDown(): void {
    foreach (glob($this->tmpDir . '/prompts/*') as $f) {
      @unlink($f);
    }
    @rmdir($this->tmpDir . '/prompts');
    @rmdir($this->tmpDir);
  }

  /**
   * Build a loader whose ExtensionPathResolver points at a tmp dir we control.
   */
  protected function makeLoader(): SystemPromptLoader {
    $resolver = $this->createMock(ExtensionPathResolver::class);
    $resolver->method('getPath')->willReturn($this->tmpDir);
    return new SystemPromptLoader($resolver);
  }

  public function testLoadsPromptFromMarkdownFile(): void {
    file_put_contents($this->tmpDir . '/prompts/query_system_prompt.v1.md', "Hello prompt v1\n");
    $loader = $this->makeLoader();
    $this->assertSame('Hello prompt v1', $loader->load('v1'));
  }

  public function testReturnsNullWhenFileMissing(): void {
    $this->assertNull($this->makeLoader()->load('v99'));
  }

  public function testCachesAfterFirstLoad(): void {
    file_put_contents($this->tmpDir . '/prompts/query_system_prompt.v1.md', 'first');
    $loader = $this->makeLoader();
    $this->assertSame('first', $loader->load('v1'));
    file_put_contents($this->tmpDir . '/prompts/query_system_prompt.v1.md', 'second');
    // Cached.
    $this->assertSame('first', $loader->load('v1'));
  }

  public function testActiveVersionDefault(): void {
    $this->assertSame('v10', $this->makeLoader()->activeVersion());
  }

  public function testActiveVersionOverride(): void {
    $loader = $this->makeLoader();
    $loader->setOverride('v2');
    $this->assertSame('v2', $loader->activeVersion());
    $loader->setOverride('1');
    $this->assertSame('v1', $loader->activeVersion());
    $loader->setOverride(NULL);
    $this->assertSame('v10', $loader->activeVersion());
    $loader->setOverride('');
    $this->assertSame('v10', $loader->activeVersion());
  }

  public function testStripsTrailingWhitespace(): void {
    file_put_contents($this->tmpDir . '/prompts/query_system_prompt.v1.md', "  body\n\n\n");
    $this->assertSame('body', $this->makeLoader()->load('v1'));
  }

  public function testVersionPrefixHandling(): void {
    file_put_contents($this->tmpDir . '/prompts/query_system_prompt.v2.md', 'two');
    $loader = $this->makeLoader();
    // Either form should resolve.
    $this->assertSame('two', $loader->load('v2'));
    // New instance to avoid the per-version cache hit.
    $loader2 = $this->makeLoader();
    $this->assertSame('two', $loader2->load('2'));
  }

}
