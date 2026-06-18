<?php

namespace UpdateTool\Hubph;

class PullRequests implements \Iterator
{
    protected $prs = [];
    protected $position = 0;

    public function __construct()
    {
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function current(): mixed
    {
        return $this->prs[$this->position];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function valid(): bool
    {
        return isset($this->prs[$this->position]);
    }

    public function addSearchResults($searchResults, $pattern = '')
    {
        $total = $searchResults['total_count'];
        $incomplete = $searchResults['incomplete_results'];

        foreach ($searchResults['items'] as $pr) {
            if (empty($pattern) || preg_match("#{$pattern}#", $pr['title'])) {
                $this->add($pr);
            }
        }
    }

    public function add($pr)
    {
        $this->prs[] = $pr;
    }

    public function titles()
    {
        $titles = array_map(
            function ($pr) {
                return $pr['title'];
            },
            $this->prs
        );
        return $titles;
    }

    public function prNumbers()
    {
        $prNumbers = [];
        foreach ($this->prs as $pr) {
            $prNumbers[] = $pr['number'];
        }
        return $prNumbers;
    }

    public function isEmpty()
    {
        return empty($this->prs);
    }
}
