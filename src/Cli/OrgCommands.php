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
use UpdateTool\Util\ProjectUpdate;

use UpdateTool\CircleCI\CircleAPI;
use Symfony\Component\Yaml\Yaml;

class OrgCommands extends \Robo\Tasks implements ConfigAwareInterface, LoggerAwareInterface
{
    use ConfigAwareTrait;
    use LoggerAwareTrait;

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
     *   circle_ci: Uses Circle CI
     *   circle_vars: Circle Env Vars
     *   circle_contexts: Circle Contexts
     *   admins: Admins
     *   codeowners: Code Owners
     *   owners_src: Owners Source
     *   ownerTeam: Owning Team
     *   support_level: Support Level
     *   branch_protections: Branch Protections
     * @default-fields full_name,codeowners,owners_src,circle_vars,circle_contexts,support_level
     * @default-string-field full_name
     *
     * @return Consolidation\OutputFormatters\StructuredData\RowsOfFields
     */
    public function orgAnalyze($org, $options = [
        'as' => 'default',
        'format' => 'table',
        'only-public' => false,
        'only-private' => false,
        'include-archived' => false,
        'include-admins' => false,
        'include-branch-protections' => false,
        'forks' => true,
    ])
    {
        $api = $this->api($options['as']);
        $pager = $api->resultPager();

        $repoApi = $api->gitHubAPI()->api('repo');
        $orgApi = $api->gitHubAPI()->api('organization');
        $protectionAPI = $repoApi->protection();
        $teamsApi = $orgApi->teams();

        // Get list of all admins of the org, so that we can exclude these from
        // the user list.
        $orgAdmins = [];
        if ($options['include-admins']) {
            $orgMembersApi = $orgApi->members();
            $members = $pager->fetchAll($orgMembersApi, 'all', [$org]);
            foreach ($members as $id => $member) {
                $memberData = $orgMembersApi->member($org, $member['login']);
                if ($memberData['role'] == 'admin') {
                    $orgAdmins[] = $member['login'];
                }
            }
        }

        $repos = $pager->fetchAll($orgApi, 'repositories', [$org]);

        // Remove archived repositories from consideration
        if (!$options['include-archived']) {
            $repos = array_filter($repos, function ($repo) {
                return empty($repo['archived']);
            });
        }

        // Remove private repos.
        if ($options['only-public']) {
            $repos = array_filter($repos, function ($repo) {
                return empty($repo['private']);
            });
        }

        // Remove public repos.
        if ($options['only-private']) {
            $repos = array_filter($repos, function ($repo) {
                return !empty($repo['private']);
            });
        }

        // Remove fork repos.
        if (!$options['forks']) {
            $repos = array_filter($repos, function ($repo) {
                return empty($repo['fork']);
            });
        }

        $circleApi = new CircleAPI();

        // TEMPORARY: only do the first 10
        // $repos = array_splice($repos, 0, 10);

        // Add CODEOWNER information to repository data
        $reposResult = [];
        foreach ($repos as $key => $repo) {
            $resultKey = $repo['full_name'];
            $defaultBranch = $repo['default_branch'];

            list($codeowners, $ownerSource) = $this->guessCodeowners($api, $org, $repo['name']);

            $repo['codeowners'] = $codeowners;
            $repo['owners_src'] = $ownerSource;

            if (empty($codeowners)) {
                $repo['ownerTeam'] = 'n/a';
            } else {
                $repo['ownerTeam'] = str_replace("@$org/", "", $codeowners[0]);
            }

            //$repoMetadata = $repoApi->show($org, $repo['name']);
            //var_export($repoMetadata);

            // Fetch metadata related to repository admins
            if ($options['include-admins']) {
                $admins = [];
                $teamAdmins = [];
                try {
                    $teams = $repoApi->teams($org, $repo['name']);
                    foreach ($teams as $team) {
                        $name = $team['name'];
                        if (!empty($team['permissions']['admin'])) {
                            $admins[] = "@$org/$name";
                            $members = $teamsApi->members($name, $org);
                            foreach ($members as $member) {
                                $teamAdmins[] = $member['login'];
                            }
                        }
                    }
                    $collaborators = $repoApi->collaborators()->all($org, $repo['name']);
                    foreach ($collaborators as $id => $collaborator) {
                        $login = $collaborator['login'];
                        if (!empty($collaborator['permissions']['admin']) && !in_array($login, $orgAdmins) && !in_array($login,  $teamAdmins)) {
                            $admins[] = $login;
                        }
                    }
                } catch (\Exception $e) {

                }
                $repo['admins'] = $admins;
            }

            // Fetch metadata related to branch protections
            $branch_protections = [];
            if ($options['include-branch-protections']) {
                $protections = [];
                $protectionData = [];
                try {
                    $protectionData = $protectionAPI->show($org, $repo['name'], $defaultBranch);
                } catch (\Exception $e) {
                    $protections = ['unprotected'];
                }

                // NOTE: We do not report restrictions on who can push to the default branch, if an allowlist has been set up for this.

                // Insert protections related to required reviews
                if (isset($protectionData['required_pull_request_reviews']) && ($protectionData['required_pull_request_reviews']['required_approving_review_count'] > 0)) {
                    $protections[] = 'required_pull_request_reviews';
                    foreach ($protectionData as $key => $value) {
                        if (is_bool($value) && $value) {
                            $protections[] = $key;
                        }
                    }
                }
                // Insert protections with generic structured ('enabled' => true)
                foreach ($protectionData as $key => $protection) {
                    if (is_array($protection) && !empty($protection['enabled'])) {
                        $protections[] = $key;
                    }
                }

                $repo['branch_protections'] = $protections;
            }

            // Fetch metadata related to contents of README file
            try {
                $data = $api->gitHubAPI()->api('repo')->contents()->show($org, $repo['name'], 'README.md');
                if (!empty($data['content'])) {
                    $content = base64_decode($data['content']);
                    $support_level = SupportLevel::getSupportLevelsFromContent($content);
                    $repo['support_level'] = count($support_level) ? reset($support_level) : 'EMPTY';
                }
            } catch (\Exception $e) {
                $repo['support_level'] = 'EMPTY';
            }

            // Fetch metadata related to CircleCI
            $repo['circle_ci'] = false;
            $repo['circle_vars'] = [];
            $repo['circle_contexts'] = [];
            try {
                $data = $api->gitHubAPI()->api('repo')->contents()->show($org, $repo['name'], '.circleci/config.yml');
                $repo['circle_ci'] = true;
                if (!empty($data['content'])) {
                    $content = base64_decode($data['content']);
                    $circleConfig = Yaml::parse($content);
                    $repo['circle_contexts'] = $this->findCircleContexts($circleConfig);
                }
                $circleVars = [];
                list($status, $circleVars) = $circleApi->envVars($org, $repo['name']);
                $repo['circle_vars'] = $circleVars;
            } catch (\Exception $e) {
            }

            $reposResult[$resultKey] = $repo;
        }

        $data = new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($reposResult);
        $this->addTableRenderFunction($data);

        return $data;
    }

    protected function findCircleContexts($circleConfig)
    {
        $contexts = [];

        foreach ($circleConfig as $key => $value) {
            if (is_array($value)) {
                $additions = [];
                if ($key === 'context') {
                    $additions = array_values($value);
                } else {
                    $additions = $this->findCircleContexts($value);
                }
                $contexts = array_merge($contexts, $additions);
            }
        }
        sort($contexts);
        $contexts = array_unique($contexts);

        return $contexts;
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
     * Get right column number based on column title.
     */
    public function getColumnNumber($columnTitle, $headerRow)
    {
        foreach ($headerRow as $key => $value) {
            if ($value == $columnTitle) {
                return $key;
            }
        }
        throw new \Exception("Column $columnTitle not found in given header row.");
    }

    /**
     * @command org:update-projects-info
     * @param $csv_file The path to csv file that contains projects information.
     */
    public function orgUpdateProjectsInfo($csv_file, $options = [
        'as' => 'default',
        'update-codeowners' => false,
        'codeowners-only-api' => false,
        'codeowners-only-guess' => false,
        'codeowners-only-owner' => '',
        'update-support-level-badge' => false,
        'branch-name' => 'project-update-info',
        'commit-message' => 'Update project information.',
        'error-log-file' => 'error.log',
        'skip-on-empty-support-level' => true,
        'pr-body' => '',
        'pr-body-unsupported' => '',
        'pr-title' => '[UpdateTool - Project Information] Update project information.',
    ])
    {
        $api = $this->api($options['as']);
        $updateCodeowners = $options['update-codeowners'];
        $codeownersOnlyApi = $options['codeowners-only-api'];
        $codeownersOnlyGuess = $options['codeowners-only-guess'];
        $updateSupportLevelBadge = $options['update-support-level-badge'];
        if (!$updateCodeowners && !$updateSupportLevelBadge) {
            throw new \Exception("Either --update-codeowners or --update-support-level-badge must be specified.");
        }
        if (!$updateCodeowners && ($codeownersOnlyApi || $codeownersOnlyGuess)) {
            throw new \Exception("--codeowners-only-api and --codeowners-only-guess can only be used with --update-codeowners.");
        }
        if ($codeownersOnlyGuess && $codeownersOnlyApi) {
            throw new \Exception("--codeowners-only-api and --codeowners-only-guess can't be used together.");
        }
        $branchName = $options['branch-name'];
        $commitMessage = $options['commit-message'];
        $projectUpdate = new ProjectUpdate($this->logger);

        if (file_exists($csv_file)) {
            $csv = new \SplFileObject($csv_file);
            $csv->setFlags(\SplFileObject::READ_CSV);
            $projectFullNameIndex = -1;
            $projectOrgIndex = -1;
            $projectSupportLevelIndex = -1;
            $projectDefaultBranchIndex = -1;
            foreach ($csv as $row_id => $row) {
                $prBody = $options['pr-body'];
                // Get column indexes if header row.
                if ($row_id == 0) {
                    $projectFullNameIndex = $this->getColumnNumber('full_name', $row);
                    $projectOrgIndex = $this->getColumnNumber('owner/login', $row);
                    $projectSupportLevelIndex = $this->getColumnNumber('Support Level', $row);
                    $projectDefaultBranchIndex = $this->getColumnNumber('default_branch', $row);
                    continue;
                }
                if (empty($row[0])) {
                    // Empty line, probably EOF. Break loop.
                    break;
                }
                $projectUpdateSupportLevel = $updateSupportLevelBadge;
                $projectUpdateCodeowners = $updateCodeowners;
                $projectFullName = $row[$projectFullNameIndex];
                $projectOrg = $row[$projectOrgIndex];
                $projectSupportLevel = $row[$projectSupportLevelIndex];
                $projectDefaultBranch = $row[$projectDefaultBranchIndex];
                $codeowners = '';
                $ownerSource = '';
                if ($this->validateProjectFullName($projectFullName) && !empty($projectDefaultBranch) && !empty($projectOrg)) {
                    // If empty or invalid support level, we won't update it here.
                    if ($projectUpdateSupportLevel && (empty($projectSupportLevel) || !$this->validateProjectSupportLevel($projectSupportLevel))) {
                        $projectUpdateSupportLevel = false;
                        if ($options['skip-on-empty-support-level']) {
                            $this->logger->warning(sprintf("Skipping project %s because of empty or invalid support level.", $projectFullName));
                            continue;
                        }
                    } elseif ($projectSupportLevel === 'Unsupported' || $projectSupportLevel === 'Deprecated') {
                        $prBody = $options['pr-body-unsupported'] ?? $prBody;
                    }
                    if ($projectUpdateCodeowners) {
                        list($codeowners, $ownerSource) = $this->guessCodeowners($api, $projectOrg, $projectFullName);
                        if (empty($codeowners) || $ownerSource === 'file') {
                            $projectUpdateCodeowners = false;
                        } else {
                            if ($codeownersOnlyApi && $ownerSource !== 'api') {
                                $projectUpdateCodeowners = false;
                            } elseif ($codeownersOnlyGuess && $ownerSource !== 'guess') {
                                $projectUpdateCodeowners = false;
                            } else {
                                $codeowners = implode('\n', $codeowners);
                            }
                        }
                        if ($options['codeowners-only-owner'] && $codeowners !== $options['codeowners-only-owner']) {
                            $codeowners = '';
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
                    try {
                        $projectUpdate->updateProjectInfo($api, $projectFullName, $projectDefaultBranch, $options['branch-name'], $options['commit-message'], $options['pr-title'], $prBody, $projectSupportLevel, $codeowners);
                    } catch (\Exception $e) {
                        $this->writeToLogFile($projectFullName, $options['error-log-file']);
                        $this->logger->warning("Failed to update project information for $projectFullName: " . $e->getMessage());
                    }
                }
            }
        } else {
            throw new \Exception("File $csv_file does not exist.");
        }
    }

    /**
     * Write to log file.
     */
    protected function writeToLogFile($message, $filename)
    {
        $contents = '';
        if (file_exists($filename)) {
            $contents = file_get_contents($filename);
        }
        $contents .= $message . "\n";
        file_put_contents($filename, $contents);
    }

    /**
     * @command org:update-projects-merge-prs
     * @param $user The Github user (org) to search PRs for.
     */
    public function orgUpdateProjectsMergePrs($user, $options = [
        'as' => 'default',
        'age' => 30,
        'pr-title-query' => '[UpdateTool - Project Information]',
        'pr-title-pattern' => '',
        'diff-include-pattern' => '',
        'diff-exclude-pattern' => '',
        'dry-run' => false,
    ])
    {
        $api = $this->api($options['as']);
        $prTitleQuery = $options['pr-title-query'];
        $prTitlePattern = $options['pr-title-pattern'];
        $age = $options['age'];

        $prs = $api->matchingPRsInUser($user, $prTitleQuery, $prTitlePattern);
        $current_date = new \DateTime();
        foreach ($prs as $pr) {
            $prNumber = $pr['number'];
            $prUrl = $pr['html_url'];
            preg_match('/https:\/\/github.com\/' . $user . '\/(.+)\/pull\/' . $prNumber . '/', $prUrl, $matches);
            if (empty($matches[1])) {
                $this->logger->warning("Failed to parse project name from $prUrl.");
                continue;
            }
            $projectName = $matches[1];
            $pr = $api->prGet($user, $projectName, $prNumber);
            $updated = $pr['created_at'];
            $date = new \DateTime($updated);
            $interval = $current_date->diff($date);
            $days = (int) $interval->format('%a');
            if ($days >= $age) {
                if (count($api->prGetComments($user, $projectName, $prNumber)) > 0) {
                    $this->logger->warning("Skipping PR $prNumber in $projectName because it has comments or reviews.");
                    continue;
                }
                $diff = $api->prGetDiff($user, $projectName, $prNumber);
                if ($options['diff-include-pattern'] && !preg_match('/' . $options['diff-include-pattern'] . '/', $diff)) {
                    $this->logger->warning("Skipping PR $prNumber in $projectName because it does not match diff-include-pattern.");
                    continue;
                }
                if ($options['diff-exclude-pattern'] && preg_match('/' . $options['diff-exclude-pattern'] . '/', $diff)) {
                    $this->logger->warning("Skipping PR $prNumber in $projectName because it matches diff-exclude-pattern.");
                    continue;
                }
                $prSha = $pr['head']['sha'];
                // This PR is ready to merge.
                if (!$options['dry-run']) {
                    $this->logger->notice("Merging PR #$prNumber in $projectName because it is old enough.");
                    $api->gitHubAPI()->api('pull_request')->merge($user, $projectName, $prNumber, "Auto merge PR #$prNumber in $projectName", $prSha);
                    break;
                } else {
                    $this->logger->notice("PR #$prNumber in $projectName would be merged if no dry-run because it is old enough.");
                }
            }
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
                    if (in_array($key, ['permissions', 'circle_vars'])) {
                        return implode(',', array_filter(array_keys($cellData)));
                    }
                    if (in_array($key, ['codeowners', 'admins', 'branch_protections'])) {
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
