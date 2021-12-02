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
use UpdateTool\Util\SupportLevel;
use UpdateTool\Util\ProjectUpdateTrait;

class OrgCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;
    use ProjectUpdateTrait;

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
     *   support_level: Support Level
     * @default-fields full_name,codeowners,owners_src,support_level
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

            list($codeowners, $ownerSource) = $this->guessCodeowners($api, $org, $repo['name']);

            $repo['codeowners'] = $codeowners;
            $repo['owners_src'] = $ownerSource;

            if (empty($codeowners)) {
                $repo['ownerTeam'] = 'n/a';
            } else {
                $repo['ownerTeam'] = str_replace("@$org/", "", $codeowners[0]);
            }

            try {
                $data = $api->gitHubAPI()->api('repo')->contents()->show($org, $repo['name'], 'README.md');
                if (!empty($data['content'])) {
                    $content = base64_decode($data['content']);
                    $repo['support_level'] = static::getSupportLevel($content);
                }
            } catch (\Exception $e) {
            }

            $reposResult[$resultKey] = $repo;
        }

        $data = new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($reposResult);
        $this->addTableRenderFunction($data);

        return $data;
    }

    /**
     * Guess codeowners content.
     */
    public function guessCodeOwners($api, $org, $repoName)
    {
        $codeowners = [];
        $ownerSource = '';
        try {
            $data = $api->gitHubAPI()->api('repo')->contents()->show($org, $repoName, 'CODEOWNERS');
            if (!empty($data['content'])) {
                $content = base64_decode($data['content']);
                $ownerSource = 'file';
                $codeowners = static::filterGlobalCodeOwners($content);
            }
        } catch (\Exception $e) {
        }

        return static::inferOwners($api, $org, $repoName, $codeowners, $ownerSource);
    }

    /**
     * @command org:update-projects-info
     * @param $csv_file The path to csv file that contains projects information.
     */
    public function orgUpdateProjectsInfo($csv_file, $options = [
        'as' => 'default',
        'update-codeowners' => false,
        'update-support-level-badge' => false,
        'branch-name' => 'project-update-info',
        'commit-message' => 'Update project information.',
        'pr-body' => '',
    ])
    {
        $api = $this->api($options['as']);
        $updateCodeowners = $options['update-codeowners'];
        $updateSupportLevelBadge = $options['update-support-level-badge'];
        if (!$updateCodeowners && !$updateSupportLevelBadge) {
            throw new \Exception("Either --update-codeowners or --update-support-level-badge must be specified.");
        }
        $prTitle = '[UpdateTool - Project Information] Update project information.';
        $branchName = $options['branch-name'];
        $commitMessage = $options['commit-message'];
        if (file_exists($csv_file)) {
            $csv = new \SplFileObject($csv_file);
            $csv->setFlags(\SplFileObject::READ_CSV);
            foreach ($csv as $row_id => $row) {
                // Skip header row.
                if ($row_id == 0) {
                    continue;
                }
                $projectUpdateSupportLevel = $updateSupportLevelBadge;
                $projectUpdateCodeowners = $updateCodeowners;
                $projectFullName = $row[3];
                $projectOrg = $row[5];
                $projectSupportLevel = $row[23];
                $projectDefaultBranch = $row[97];
                $codeowners = '';
                $ownerSource = '';
                if ($this->validateProjectFullName($projectFullName) && !empty($projectDefaultBranch) && !empty($projectOrg)) {
                    // If empty or invalid support level, we won't update it here.
                    if ($projectUpdateSupportLevel && (empty($projectSupportLevel) || !$this->validateProjectSupportLevel($projectSupportLevel))) {
                        $projectUpdateSupportLevel = false;
                    }
                    if ($projectUpdateCodeowners) {
                        list($codeowners, $ownerSource) = $this->guessCodeowners($api, $projectOrg, $projectFullName);
                        if (empty($codeowners)) {
                            // @todo: Should we decide a course of action based on $ownerSource?
                            $projectUpdateCodeowners = false;
                        } else {
                            $codeowners = implode('\n', $codeowners);
                        }
                    }
                } else {
                    $projectUpdateSupportLevel = false;
                    $projectUpdateCodeowners = false;
                }
                if ($projectUpdateSupportLevel || $projectUpdateCodeowners) {
                    if (!$projectUpdateSupportLevel) {
                        $projectSupportLevel = null;
                    }
                    $this->updateProjectInfo($api, $projectFullName, $projectDefaultBranch, $options['branch-name'], $options['commit-message'], $prTitle, $options['pr-body'], $this->logger, $projectSupportLevel, $codeowners);
                }
                break;
            }
        } else {
            throw new \Exception("File $csv_file does not exist.");
        }
    }

    /**
     * @command org:update-projects-merge-prs
     * @param $csv_file The path to csv file that contains projects information.
     */
    public function orgUpdateProjectsMergePrs($csv_file, $options = [
        'as' => 'default',
        'branch-name' => 'project-update-info',
        'pr-title' => 'Update to Drupal',
    ])
    {
        $api = $this->api($options['as']);
        $prs = $api->matchingPRs('pantheon-systems/drops-7', $options['pr-title']);
        var_dump($prs->prNumbers());
        foreach ($prs as $key => $pr) {
            var_dump($pr);
            var_dump($key);
        }
    }

    /**
     * Validate project full name. Throw exception if invalid.
     */
    protected function validateProjectFullName($projectFullName)
    {
        if (empty($projectFullName)) {
            return false;
        }
        $parts = explode('/', $projectFullName);
        if (count($parts) != 2) {
            return false;
        }
        return true;
    }

    /**
     * Validate project support level. Throw exception if invalid.
     */
    protected function validateProjectSupportLevel($projectSupportLevel)
    {
        $labels = SupportLevel::getBadgesLabels();
        foreach ($labels as $key => $label) {
            if ($projectSupportLevel === $label) {
                return true;
            } elseif ($projectSupportLevel === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get support level from README.md contents.
     */
    protected static function getSupportLevel($readme_contents)
    {
        $support_level = null;
        $lines = explode("\n", $readme_contents);
        $badges = SupportLevel::getSupportLevelBadges();
        foreach ($lines as $line) {
            foreach ($badges as $key => $badge) {
                // Get the badge text from the badge markup.
                preg_match(SupportLevel::SUPPORT_LEVEL_BADGE_LABEL_REGEX, $badge, $matches);
                if (!empty($matches[1])) {
                    if (strpos($line, $matches[1]) !== false) {
                        $support_level = $key;
                        break 2;
                    }
                }
            }
        }
        if ($support_level) {
            return SupportLevel::getSupportLevelLabel($support_level);
        }
        return null;
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
