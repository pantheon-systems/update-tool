<?php

namespace UpdateTool\Cli;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\Filter\FilterOutputData;
use Consolidation\Filter\LogicalOpFactory;
use Consolidation\OutputFormatters\Options\FormatterOptions;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Consolidation\AnnotatedCommand\CommandError;
use Hubph\HubphAPI;
use Hubph\VersionIdentifiers;
use Hubph\PullRequests;
use Hubph\Git\WorkingCopy;
use Hubph\Git\Remote;

class OrgCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;

    /**
     * @command repo:convert-data
     */
    public function repoConvertData($path, $options = ['format' => 'json'])
    {
        $repos = json_decode(file_get_contents($path), true);

        $reposResult = [];
        foreach ($repos as $key => $spec) {
            list($org, $project) = explode('/', $spec['full_name'], 2);
            $spec['org'] = $org;

            $repo = [
                'kind' => 'repository',
                'metadata' => [
                    'name' => $project,
                ],
                'spec' => $spec,
            ];

            $reposResult[] = $repo;
        }

        return $reposResult;
    }

    /**
     * @command org:analyze
     * @param $org The org to list
     * @filter-output
     * @field-labels
     *   url: Url
     *   id: ID
     *   owner: Owner
     *   name: Shortname
     *   full_name: Name
     *   private: Private
     *   fork: Fork
     *   created_at: Created
     *   updated_at: Updated
     *   pushed_at: Pushed
     *   git_url: Git URL
     *   ssh_url: SSH URL
     *   svn_url: SVN URL
     *   homepage: Homepage
     *   size: Size
     *   stargazers_count: Stargazers
     *   watchers_count: Watchers
     *   language: Language
     *   has_issues: Has Issues
     *   has_projects: Has Projects
     *   has_downloads: Has Downloads
     *   has_wiki: Has Wiki
     *   has_pages: Has Pages
     *   forks_count: Forks
     *   archived: Archived
     *   disabled: Disabled
     *   open_issues_count: Open Issues
     *   default_branch: Default Branch
     *   license: License
     *   permissions: Permissions
     *   codeowners: Code Owners
     *   owners_src: Owners Source
     *   ownerTeam: Owning Team
     * @default-fields full_name,codeowners,owners_src
     * @default-string-field full_name
     *
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function orgAnalyze($org, $options = ['as' => 'default', 'format' => 'table'])
    {
        $api = $this->api($options['as']);
        $pager = $api->resultPager();

        $repoApi = $api->gitHubAPI()->api('organization');
        $repos = $pager->fetchAll($repoApi, 'repositories', [$org]);

        // Remove archived repositories from consideration
        $repos = array_filter($repos, function ($repo) {
            return empty($repo['archived']);
        });

        // TEMPORARY: only do the first 20
        // $repos = array_splice($repos, 0, 20);

        // Add CODEOWNER information to repository data
        $reposResult = [];
        foreach ($repos as $key => $repo) {
            $resultKey = $repo['id'];
            $codeowners = [];
            $ownerSource = '';

            try {
                $data = $api->gitHubAPI()->api('repo')->contents()->show($org, $repo['name'], 'CODEOWNERS');
                if (!empty($data['content'])) {
                    $content = base64_decode($data['content']);
                    $ownerSource = 'file';
                    $codeowners = static::filterGlobalCodeOwners($content);
                }
            } catch (\Exception $e) {
            }

            list($codeowners, $ownerSource) = static::inferOwners($api, $org, $repo['name'], $codeowners, $ownerSource);

            $repo['codeowners'] = $codeowners;
            $repo['owners_src'] = $ownerSource;

            if (empty($codeowners)) {
                $repo['ownerTeam'] = 'n/a';
            } else {
                $repo['ownerTeam'] = str_replace("@$org/", "", $codeowners[0]);
            }

            $reposResult[$resultKey] = $repo;
        }

        $data = new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($reposResult);
        $this->addTableRenderFunction($data);

        return $data;
    }

    protected static function inferOwners($api, $org, $project, $codeowners, $ownerSource)
    {
        $owningTeams = array_filter($codeowners, function ($owner) use ($org) {
            // @pantheon-systems/sig-go is in the default CODEOWNERS file for the go-demo-service
            if (($owner == '@pantheon-systems/sig-go') || ($owner == '@pantheon-systems/upstream-maintenance')) {
                return false;
            }
            return preg_match("/^@$org/", $owner);
        });

        // Our standard is that only TEAMS should be global code owners, but we
        // do have some examples with teams and individuals. For now we are
        // stripping out the individuals.
        if (!empty($owningTeams)) {
            return [$owningTeams, $ownerSource];
        }

        $teams = [];

        try {
            // Use the API to look up teams that have access to the repo and might be owners
            $teamsWithAccess = $api->gitHubAPI()->api('repo')->teams($org, $project);
            $teamsWithAdmin = [];
            $teamsWithWrite = [];
            foreach ($teamsWithAccess as $team) {
                if ($team['permissions']['admin']) {
                    $teamsWithAdmin[] = $team['slug'];
                } elseif ($team['permissions']['push']) {
                    $teamsWithWrite[] = $team['slug'];
                }
            }

            // If there are any teams with admin, use them. Otherwise fall back to teams with write.
            $teams = empty($teamsWithAdmin) ? $teamsWithWrite : $teamsWithAdmin;
        } catch (\Exception $e) {
        }

        // Convert from team slug to @org/slug
        $teams = array_map(function ($team) use ($org) {
            return "@$org/$team";
        }, $teams);

        if (!empty($teams)) {
            return [$teams, 'api'];
        }

        // Infer some owners
        $inferences = [
            'autopilot' => '@pantheon-systems/otto',
            'terminus' => '@pantheon-systems/cms-ecosystem',
            'drupal' => '@pantheon-systems/cms-ecosystem',
            'wordpress' => '@pantheon-systems/cms-ecosystem',
            'wp-' => '@pantheon-systems/cms-ecosystem',
            'cos-' => '@pantheon-systems/platform',
            'fastly' => '@pantheon-systems/platform-edge-routing',
        ];
        foreach ($inferences as $pat => $owner) {
            if (strpos($project, $pat) !== false) {
                return [[$owner], 'guess'];
            }
        }

        return [[], ''];
    }

    protected static function filterGlobalCodeOwners($content)
    {
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\*[ \t]/', $line)) {
                $globalOwners = str_replace("\t", " ", trim(ltrim($line, '*')));
                return explode(' ', $globalOwners);
            }
        }
        return [];
    }

    protected function addTableRenderFunction($data)
    {
        $data->addRendererFunction(
            function ($key, $cellData, FormatterOptions $options, $rowData) {
                if (empty($cellData)) {
                    return '';
                }
                if (is_array($cellData)) {
                    if ($key == 'permissions') {
                        return implode(',', array_filter(array_keys($cellData)));
                    }
                    if ($key == 'codeowners') {
                        return implode(' ', array_filter($cellData));
                    }
                    foreach (['login', 'label', 'name'] as $k) {
                        if (isset($cellData[$k])) {
                            return $cellData[$k];
                        }
                    }
                    // TODO: simplify
                    //   assignees
                    //   requested_reviewers
                    //   requested_teams
                    //   labels
                    //   _links
                    return json_encode($cellData, true);
                }
                if (!is_string($cellData)) {
                    return var_export($cellData, true);
                }
                return $cellData;
            }
        );
    }

    protected function api($as = 'default')
    {
        $api = new HubphAPI($this->getConfig());
        $api->setAs($as);

        return $api;
    }
}
