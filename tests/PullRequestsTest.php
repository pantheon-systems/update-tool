<?php

namespace UpdateTool;

use PHPUnit\Framework\TestCase;
use UpdateTool\Hubph\PullRequests;

class PullRequestsTest extends TestCase
{
    public function testIsEmptyOnNew()
    {
        $prs = new PullRequests();
        $this->assertTrue($prs->isEmpty());
    }

    public function testAddMakesNotEmpty()
    {
        $prs = new PullRequests();
        $prs->add(['number' => 1, 'title' => 'Test PR']);
        $this->assertFalse($prs->isEmpty());
    }

    public function testTitles()
    {
        $prs = new PullRequests();
        $prs->add(['number' => 1, 'title' => 'First PR']);
        $prs->add(['number' => 2, 'title' => 'Second PR']);

        $this->assertEquals(['First PR', 'Second PR'], $prs->titles());
    }

    public function testPrNumbers()
    {
        $prs = new PullRequests();
        $prs->add(['number' => 42, 'title' => 'PR 42']);
        $prs->add(['number' => 99, 'title' => 'PR 99']);

        $this->assertEquals([42, 99], $prs->prNumbers());
    }

    public function testIterator()
    {
        $prs = new PullRequests();
        $prs->add(['number' => 1, 'title' => 'A']);
        $prs->add(['number' => 2, 'title' => 'B']);

        $collected = [];
        foreach ($prs as $key => $pr) {
            $collected[$key] = $pr['title'];
        }
        $this->assertEquals([0 => 'A', 1 => 'B'], $collected);
    }

    public function testIteratorRewind()
    {
        $prs = new PullRequests();
        $prs->add(['number' => 1, 'title' => 'A']);

        // Iterate once
        foreach ($prs as $pr) {
        }
        // Iterate again to test rewind
        $count = 0;
        foreach ($prs as $pr) {
            $count++;
        }
        $this->assertEquals(1, $count);
    }

    public function testAddSearchResults()
    {
        $prs = new PullRequests();
        $searchResults = [
            'total_count' => 2,
            'incomplete_results' => false,
            'items' => [
                ['number' => 10, 'title' => 'Update to WordPress 6.5'],
                ['number' => 11, 'title' => 'Update to Drupal 10.3'],
            ],
        ];

        $prs->addSearchResults($searchResults);
        $this->assertEquals(['Update to WordPress 6.5', 'Update to Drupal 10.3'], $prs->titles());
    }

    public function testAddSearchResultsWithPattern()
    {
        $prs = new PullRequests();
        $searchResults = [
            'total_count' => 2,
            'incomplete_results' => false,
            'items' => [
                ['number' => 10, 'title' => 'Update to WordPress 6.5'],
                ['number' => 11, 'title' => 'Update to Drupal 10.3'],
            ],
        ];

        $prs->addSearchResults($searchResults, 'WordPress');
        $titles = $prs->titles();
        $this->assertCount(1, $titles);
        $this->assertEquals('Update to WordPress 6.5', $titles[0]);
    }

    public function testAddSearchResultsEmptyPattern()
    {
        $prs = new PullRequests();
        $searchResults = [
            'total_count' => 1,
            'incomplete_results' => false,
            'items' => [
                ['number' => 10, 'title' => 'Any title'],
            ],
        ];

        $prs->addSearchResults($searchResults, '');
        $this->assertCount(1, $prs->titles());
    }
}
