<?php

declare(strict_types=1);

namespace Drupal\Tests\dkan_drupal_ai_query_mock\Unit\Service;

use Drupal\Core\State\StateInterface;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\dkan_drupal_ai_query_mock\Scenario;
use Drupal\dkan_drupal_ai_query_mock\Service\ScenarioLoader;
use Drupal\dkan_drupal_ai_query_mock\Service\ScenarioMatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit-tests for ScenarioMatcher: header > cookie > state > question > NULL.
 *
 * @covers \Drupal\dkan_drupal_ai_query_mock\Service\ScenarioMatcher
 */
class ScenarioMatcherTest extends TestCase {

  /**
   * Header beats every lower-priority signal.
   */
  public function testHeaderTakesPrecedenceOverEverything(): void {
    $matcher = $this->buildMatcher(
      scenarios: [
        'first' => $this->scenario('first', ['yellowstone']),
        'second' => $this->scenario('second'),
      ],
      headerValue: 'second',
      cookieValue: 'first',
      stateValue: 'first',
    );
    $resolved = $matcher->resolve($this->chatInput('Tell me about Yellowstone'));
    $this->assertSame('second', $resolved->id);
  }

  /**
   * Cookie wins when no header is present.
   */
  public function testCookieBeatsStateAndQuestion(): void {
    $matcher = $this->buildMatcher(
      scenarios: [
        'first' => $this->scenario('first', ['yellowstone']),
        'second' => $this->scenario('second'),
      ],
      cookieValue: 'second',
      stateValue: 'first',
    );
    $resolved = $matcher->resolve($this->chatInput('Tell me about Yellowstone'));
    $this->assertSame('second', $resolved->id);
  }

  /**
   * State wins when no header / cookie present.
   */
  public function testStateBeatsQuestion(): void {
    $matcher = $this->buildMatcher(
      scenarios: [
        'first' => $this->scenario('first', ['yellowstone']),
        'forced' => $this->scenario('forced'),
      ],
      stateValue: 'forced',
    );
    $resolved = $matcher->resolve($this->chatInput('Tell me about Yellowstone'));
    $this->assertSame('forced', $resolved->id);
  }

  /**
   * Question_contains matches when no override is set.
   */
  public function testQuestionContainsFallback(): void {
    $matcher = $this->buildMatcher([
      'parks_2023' => $this->scenario('parks_2023', ['parks', '2023']),
      'unrelated' => $this->scenario('unrelated', ['weather']),
    ]);
    $resolved = $matcher->resolve($this->chatInput('Which PARKS were busiest in 2023?'));
    $this->assertSame('parks_2023', $resolved->id);
  }

  /**
   * Question_contains needles must ALL be present (case-insensitive AND).
   */
  public function testQuestionContainsRequiresAllNeedles(): void {
    $matcher = $this->buildMatcher([
      'both' => $this->scenario('both', ['parks', '2023']),
    ]);
    $resolved = $matcher->resolve($this->chatInput('Parks in 2024'));
    $this->assertNull($resolved);
  }

  /**
   * No match across any tier returns NULL so the caller emits a stub turn.
   */
  public function testReturnsNullWhenNothingMatches(): void {
    $matcher = $this->buildMatcher(['only' => $this->scenario('only', ['xyzzy'])]);
    $this->assertNull($matcher->resolve($this->chatInput('Hello world')));
  }

  /**
   * Unknown header / cookie / state ids fall through (do not raise).
   */
  public function testUnknownIdsFallThrough(): void {
    $matcher = $this->buildMatcher(
      scenarios: ['only' => $this->scenario('only', ['hello'])],
      headerValue: 'no_such_scenario',
      cookieValue: 'also_missing',
      stateValue: 'gone',
    );
    $resolved = $matcher->resolve($this->chatInput('hello there'));
    $this->assertSame('only', $resolved->id);
  }

  /**
   * LatestUserMessage returns the most-recent user role text.
   */
  public function testLatestUserMessage(): void {
    $matcher = $this->buildMatcher();
    $input = new ChatInput([
      new ChatMessage('user', 'first'),
      new ChatMessage('assistant', 'reply 1'),
      new ChatMessage('user', 'second'),
      new ChatMessage('assistant', 'reply 2'),
    ]);
    $this->assertSame('second', $matcher->latestUserMessage($input));
  }

  /**
   * LatestUserQuestion unwraps the ai_agents Task Description template.
   */
  public function testLatestUserQuestionUnwrapsTaskTemplate(): void {
    $matcher = $this->buildMatcher();
    $wrapped = "Task Title: \nTask Author: \nTask Description:\nWhich parks had the most visitors in 2023?\n--------------------------\n";
    $input = new ChatInput([new ChatMessage('user', $wrapped)]);
    $this->assertSame('Which parks had the most visitors in 2023?', $matcher->latestUserQuestion($input));
  }

  /**
   * LatestUserQuestion picks the last paragraph.
   *
   * The controller appends the user question after the catalog hints, so the
   * question is the last block before the closing dashes.
   */
  public function testLatestUserQuestionExtractsTrailingQuestion(): void {
    $matcher = $this->buildMatcher();
    // Mirrors NlQueryController — hints (catalog) first, then question.
    $wrapped = "Task Title: \nTask Author: \nTask Description:\n"
      . "Available datasets in this catalog (use these titles…):\n"
      . "- \"National Parks Visitation\" — abc__123\n"
      . "- \"City Air Quality\" — def__456\n\n"
      . "What about Yellowstone?\n--------------------------\n";
    $input = new ChatInput([new ChatMessage('user', $wrapped)]);
    $this->assertSame('What about Yellowstone?', $matcher->latestUserQuestion($input));
  }

  /**
   * LatestUserQuestion falls back to the full message if the wrapper is absent.
   */
  public function testLatestUserQuestionPassesThroughPlainText(): void {
    $matcher = $this->buildMatcher();
    $input = new ChatInput([new ChatMessage('user', 'just a question')]);
    $this->assertSame('just a question', $matcher->latestUserQuestion($input));
  }

  /**
   * Question matching uses the unwrapped text.
   *
   * Catalog lists in the agent task wrapper must NOT trigger spurious
   * scenario matches.
   */
  public function testMatcherIgnoresCatalogLeakInTaskWrapper(): void {
    $matcher = $this->buildMatcher([
      'parks_2023' => $this->scenario('parks_2023', ['parks', '2023']),
    ]);
    // The catalog list inside the wrapper mentions parks and 2023, but the
    // user's actual question is unrelated — match must fail.
    $wrapped = "Task Title: \nTask Description:\nWhat is the weather?\n----------\n"
      . "Available datasets:\n- National Parks Visitation 2023\n";
    $input = new ChatInput([new ChatMessage('user', $wrapped)]);
    $this->assertNull($matcher->resolve($input));
  }

  /**
   * Builds a Scenario value object with optional auto-match needles.
   */
  private function scenario(string $id, array $needles = []): Scenario {
    return new Scenario(
      id: $id,
      description: '',
      match: $needles ? ['question_contains' => $needles] : [],
      turns: [['type' => 'final_answer', 'content' => "stub for $id"]],
    );
  }

  /**
   * Builds a ChatInput containing a single user message.
   */
  private function chatInput(string $userText): ChatInput {
    return new ChatInput([new ChatMessage('user', $userText)]);
  }

  /**
   * Wires a ScenarioMatcher with mocked loader / request / state.
   */
  private function buildMatcher(
    array $scenarios = [],
    ?string $headerValue = NULL,
    ?string $cookieValue = NULL,
    ?string $stateValue = NULL,
  ): ScenarioMatcher {
    $loader = $this->createMock(ScenarioLoader::class);
    $loader->method('all')->willReturn($scenarios);
    $loader->method('get')->willReturnCallback(static fn (string $id) => $scenarios[$id] ?? NULL);

    $request = Request::create('/');
    if ($headerValue !== NULL) {
      $request->headers->set(ScenarioMatcher::HEADER, $headerValue);
    }
    if ($cookieValue !== NULL) {
      $request->cookies->set(ScenarioMatcher::COOKIE, $cookieValue);
    }
    $stack = new RequestStack();
    $stack->push($request);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static fn (string $key, $default = NULL) => $key === ScenarioMatcher::STATE_KEY ? $stateValue : $default,
    );

    return new ScenarioMatcher($loader, $stack, $state);
  }

}
