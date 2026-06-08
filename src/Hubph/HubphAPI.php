<?php

namespace UpdateTool\Hubph;

use Consolidation\Config\ConfigInterface;
use GuzzleHttp\Client as HttpClient;
use UpdateTool\Hubph\Internal\EventLogger;

/**
 * GitHub API wrapper using Guzzle 7 directly.
 * Replaces the knplabs/github-api dependency.
 */
class HubphAPI
{
    protected $config;
    protected $token;
    protected $httpClient;
    protected $eventLogger;
    protected $as = 'default';

    const GITHUB_API_URL = 'https://api.github.com';

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function startLogging($filename)
    {
        $this->stopLogging();
        $this->eventLogger = new EventLogger($filename);
        $this->eventLogger->start();
    }

    public function stopLogging()
    {
        if ($this->eventLogger) {
            $this->eventLogger->stop();
        }
        $this->eventLogger = null;
    }

    public function setAs($as)
    {
        if ($as != $this->as) {
            $this->as = $as;
            $this->token = false;
            $this->httpClient = null;
        }
    }

    public function whoami()
    {
        return $this->apiGet('/user');
    }

    public function repoCreate($org, $project, $private = true, $auto_init = false)
    {
        return $this->apiPost("/orgs/{$org}/repos", [
            'name' => $project,
            'private' => $private,
            'auto_init' => $auto_init,
        ]);
    }

    public function repoDelete($org, $project)
    {
        return $this->apiDelete("/repos/{$org}/{$project}");
    }

    public function prList($org, $project, $state = 'open')
    {
        return $this->apiGet("/repos/{$org}/{$project}/pulls", ['state' => $state]);
    }

    public function prUpdate($org, $project, $number, $params)
    {
        return $this->apiPatch("/repos/{$org}/{$project}/pulls/{$number}", $params);
    }

    public function searchIssues($query)
    {
        return $this->apiGet('/search/issues', ['q' => $query]);
    }

    public function orgRepos($org, $params = [])
    {
        $page = 1;
        $allRepos = [];
        do {
            $repos = $this->apiGet("/orgs/{$org}/repos", array_merge($params, ['per_page' => 100, 'page' => $page]));
            $allRepos = array_merge($allRepos, $repos);
            $page++;
        } while (count($repos) === 100);
        return $allRepos;
    }

    public function repoContents($org, $project, $path)
    {
        return $this->apiGet("/repos/{$org}/{$project}/contents/{$path}");
    }

    public function repoTeams($org, $project)
    {
        return $this->apiGet("/repos/{$org}/{$project}/teams");
    }

    public function releasesAll($org, $project)
    {
        return $this->apiGet("/repos/{$org}/{$project}/releases");
    }

    public function releasesLatest($org, $project)
    {
        return $this->apiGet("/repos/{$org}/{$project}/releases/latest");
    }

    public function forkCreate($org, $project, $params = [])
    {
        return $this->apiPost("/repos/{$org}/{$project}/forks", $params);
    }

    public function prGet($org, $project, $id)
    {
        return $this->apiGet("/repos/{$org}/{$project}/pulls/{$id}");
    }

    public function prMergeSingle($org, $project, $number, $message, $sha)
    {
        return $this->apiPut("/repos/{$org}/{$project}/pulls/{$number}/merge", [
            'commit_message' => $message,
            'sha' => $sha,
        ]);
    }

    public function prOpen($org, $project, $title, $body, $base, $head)
    {
        $params = [
            'title' => $title,
            'body' => $body,
            'base' => $base,
            'head' => $head,
        ];
        $response = $this->apiPost("/repos/{$org}/{$project}/pulls", $params);
        $this->logEvent(__FUNCTION__, [$org, $project], $params, $response);
        return $response;
    }

    public function prCreate($org, $project, $title, $body, $base, $head)
    {
        $this->prOpen($org, $project, $title, $body, $base, $head);
        return $this;
    }

    public function prClose($org, $project, PullRequests $prs, $comment = '')
    {
        foreach ($prs->prNumbers() as $n) {
            if ($comment) {
                $this->apiPost("/repos/{$org}/{$project}/issues/{$n}/comments", [
                    'body' => $comment,
                ]);
            }
            $this->apiPatch("/repos/{$org}/{$project}/pulls/{$n}", ['state' => 'closed']);
        }
    }

    public function prMerge($org, $project, PullRequests $prs, $message, $mergeMethod = 'squash', $title = null)
    {
        $shas = [];
        foreach ($prs->prNumbers() as $n) {
            $pullRequest = $this->prGet($org, $project, $n);
            $is_clean = $pullRequest['mergeable'] && $pullRequest['mergeable_state'] == 'clean';
            if (!$is_clean) {
                return false;
            }
            $shas[$n] = $pullRequest['head']['sha'];
        }

        foreach ($shas as $n => $sha) {
            $params = [
                'commit_message' => $message,
                'sha' => $sha,
                'merge_method' => $mergeMethod,
            ];
            if ($title) {
                $params['commit_title'] = $title;
            }
            $response = $this->apiPut("/repos/{$org}/{$project}/pulls/{$n}/merge", $params);
            $this->logEvent(__FUNCTION__, [$org, $project], [$n, $message, $sha, $mergeMethod, $title], $response);
        }
        return true;
    }

    public function prCheck($projectWithOrg, VersionIdentifiers $vids)
    {
        $existingPRs = $this->existingPRs($projectWithOrg, $vids);
        $titles = $existingPRs->titles();
        $status = $vids->allExist($titles);
        return [$status, $existingPRs];
    }

    public function prStatuses($projectWithOrg, $number)
    {
        list($org, $project) = explode('/', $projectWithOrg, 2);
        $pullRequestStatus = $this->apiGet("/repos/{$org}/{$project}/pulls/{$number}/statuses");

        $filteredResults = [];
        foreach (array_reverse($pullRequestStatus) as $id => $item) {
            $filteredResults[$item['target_url']] = $item;
        }
        $pullRequestStatus = [];
        foreach ($filteredResults as $target_url => $item) {
            $pullRequestStatus[$item['id']] = $item;
        }

        uasort(
            $pullRequestStatus,
            function ($lhs, $rhs) {
                return abs(strtotime($lhs['updated_at']) - strtotime($rhs['updated_at']));
            }
        );

        return $pullRequestStatus;
    }

    public function addTokenAuthentication($url)
    {
        $token = $this->gitHubToken();
        if (!$token) {
            return $url;
        }
        if (!preg_match('#github\.com[/:]#', $url)) {
            return $url;
        }
        $projectAndOrg = $this->projectAndOrgFromUrl($url);
        return "https://{$token}:x-oauth-basic@github.com/{$projectAndOrg}.git";
    }

    protected function projectAndOrgFromUrl($remote)
    {
        $remote = preg_replace('#^git@[^:]*:#', '', $remote);
        $remote = preg_replace('#^[^:]*://[^/]*/#', '', $remote);
        $remote = preg_replace('#\.git$#', '', $remote);

        return $remote;
    }

    protected function existingPRs($projectWithOrg, VersionIdentifiers $vids)
    {
        return $this->matchingPRs($projectWithOrg, $vids->getPreamble(), $vids->pattern());
    }

    public function matchingPRs($projectWithOrg, $preamble, $pattern = '')
    {
        $q = "repo:$projectWithOrg in:title is:pr state:open $preamble";
        $result = new PullRequests();
        $searchResults = $this->apiGet('/search/issues', ['q' => $q]);
        $result->addSearchResults($searchResults, $pattern);
        return $result;
    }

    public function matchingPRsInUser($user, $preamble, $pattern = '')
    {
        $q = "user:$user in:title archived:false is:pr state:open $preamble";
        $result = new PullRequests();
        $searchResults = $this->apiGet('/search/issues', ['q' => $q, 'per_page' => 100]);
        $result->addSearchResults($searchResults, $pattern);
        return $result;
    }

    public function allPRs($projectWithOrg)
    {
        $q = "repo:$projectWithOrg in:title is:pr state:open";
        $result = new PullRequests();
        $searchResults = $this->apiGet('/search/issues', ['q' => $q]);
        $result->addSearchResults($searchResults);
        return $result;
    }

    public function prGetComments($org, $project, $id, $include_reviews = true)
    {
        $comments = $this->apiGet("/repos/{$org}/{$project}/issues/{$id}/comments");
        if ($include_reviews) {
            $reviews = $this->apiGet("/repos/{$org}/{$project}/pulls/{$id}/reviews");
            $comments = array_merge($comments, $reviews);
        }
        return $comments;
    }

    public function prGetDiff($org, $project, $id)
    {
        $client = $this->httpClient();
        $response = $client->get(self::GITHUB_API_URL . "/repos/{$org}/{$project}/pulls/{$id}", [
            'headers' => [
                'Accept' => 'application/vnd.github.diff',
            ],
        ]);
        return (string) $response->getBody();
    }

    protected function logEvent($event_name, $args, $params, $response)
    {
        if ($this->eventLogger) {
            $this->eventLogger->log($event_name, $args, $params, $response);
        }
    }

    /**
     * Return the HTTP client with authentication headers.
     */
    protected function httpClient(): HttpClient
    {
        if (!$this->httpClient) {
            $token = $this->gitHubToken();
            $this->httpClient = new HttpClient([
                'base_uri' => self::GITHUB_API_URL,
                'headers' => [
                    'Authorization' => "token {$token}",
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'UpdateTool',
                ],
            ]);
        }
        return $this->httpClient;
    }

    protected function apiGet(string $path, array $query = []): array
    {
        $options = [];
        if (!empty($query)) {
            $options['query'] = $query;
        }
        $response = $this->httpClient()->get($path, $options);
        return json_decode((string) $response->getBody(), true);
    }

    protected function apiPost(string $path, array $body = []): array
    {
        $response = $this->httpClient()->post($path, ['json' => $body]);
        return json_decode((string) $response->getBody(), true);
    }

    protected function apiPatch(string $path, array $body = []): array
    {
        $response = $this->httpClient()->patch($path, ['json' => $body]);
        return json_decode((string) $response->getBody(), true);
    }

    protected function apiPut(string $path, array $body = []): array
    {
        $response = $this->httpClient()->put($path, ['json' => $body]);
        return json_decode((string) $response->getBody(), true);
    }

    protected function apiDelete(string $path): void
    {
        $this->httpClient()->delete($path);
    }

    public function gitHubToken()
    {
        if (!$this->token) {
            $this->token = $this->getGitHubToken();
        }
        return $this->token;
    }

    protected function getGitHubToken()
    {
        $as = $this->as;
        $token = null;
        if ($as == 'default') {
            $as = $this->getConfig()->get("github.default-user");
        }

        $github_token_cache = $this->getConfig()->get("github.personal-auth-token.$as.path");
        if (file_exists($github_token_cache)) {
            $token = trim(file_get_contents($github_token_cache));
        }

        if (!$token) {
            $env_name = strtoupper(str_replace('-', '_', $as)) . '_TOKEN';
            $token = getenv($env_name);
        }

        if ($token) {
            putenv("GITHUB_TOKEN=$token");
            return $token;
        }

        return getenv('GITHUB_TOKEN');
    }

    protected function getConfig()
    {
        return $this->config;
    }
}
