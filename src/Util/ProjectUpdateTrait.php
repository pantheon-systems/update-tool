<?php

namespace UpdateTool\Util;

use UpdateTool\Git\Remote;
use UpdateTool\Git\WorkingCopy;
use UpdateTool\Util\SupportLevel;

trait ProjectUpdateTrait
{

    /**
     * Update project info (codeowners and support level badge).
     */
    protected function updateProjectInfo($api, $project, $baseBranch, $branchName, $commitMessage, $prTitle, $prBody, $logger, $supportLevelBadge = '', $codeowners = '')
    {
        $branchToClone = $baseBranch;
        if (count(explode('/', $project)) != 2) {
            throw new \Exception("Invalid project name: $project");
        }
        if (empty($codeowners) && empty($supportLevelBadge)) {
            throw new \Exception("Must specify at least one of codeowners or support-level-badge");
        }
        $url = "git@github.com:$project.git";
        $remote = new Remote($url);
        $dir = sys_get_temp_dir() . '/update-tool/' . $remote->project();

        $existingPrFound = false;

        $prs = $api->matchingPRs($project, $prTitle)->prNumbers();
        if (count($prs) === 1) {
            $prNumber = reset($prs);
            $parts = explode('/', $project);
            $fullPr = $api->prGet($parts[0], $parts[1], $prNumber);
            if ($fullPr) {
                $branchToClone = $fullPr['head']['ref'];
                $branchName = $branchToClone;
                $existingPrFound = true;
            }
        }


        $workingCopy = WorkingCopy::cloneBranch($url, $dir, $branchToClone, $api);

        $workingCopy->setLogger($logger);

        if (!$existingPrFound) {
          $workingCopy->createBranch($branchName);
          $workingCopy->switchBranch($branchName);
        }
        $codeowners_changed = false;
        $readme_changed = false;

        if (!empty($codeowners)) {
            // Append given CODEOWNERS line.
            $string_to_add = '* ' . $codeowners . "\n";
            $codeownners_content = file_get_contents("$dir/CODEOWNERS");
            if (strpos($codeownners_content, $string_to_add) === false) {
                file_put_contents("$dir/CODEOWNERS", $string_to_add, FILE_APPEND);
                $workingCopy->add("$dir/CODEOWNERS");
                $codeowners_changed = true;
            }
        }

        if (!empty($supportLevelBadge)) {
            $badge_contents = SupportLevel::getSupportLevelBadge($supportLevelBadge);
            if (!$badge_contents) {
                throw new \Exception("Invalid support level badge: $supportLevelBadge.");
            }
            if (file_exists("$dir/README.md")) {
                $readme_contents = file_get_contents("$dir/README.md");
            } else {
                $readme_contents = '';
            }

            if (!SupportLevel::compareSupportLevelFromReadmeAndBadge($readme_contents, $badge_contents)) {
                $lines = explode("\n", $readme_contents);
                [$badge_insert_line, $empty_line_after] = $this->getBadgeInsertLine($lines, $badge_contents);

                // Insert badge contents and empty line after it.
                $insert = [$badge_contents];
                if ($empty_line_after) {
                    $insert[] = '';
                }
                array_splice($lines, $badge_insert_line, 0, $insert);
                $readme_contents = implode("\n", $lines);
                file_put_contents("$dir/README.md", $readme_contents);
                $workingCopy->add("$dir/README.md");
                $readme_changed = true;
            }
        }

        if ($codeowners_changed || $readme_changed) {
            $workingCopy->commit($commitMessage);
            $workingCopy->push('origin', $branchName);
            if (!$existingPrFound) {
                $workingCopy->pr($prTitle, $prBody, $baseBranch, $branchName);
            }
        }
    }

    /**
     * Get line number where to insert the badge.
     */
    protected function getBadgeInsertLine($readme_lines, $badge_contents = '', $number_of_lines_to_search = 5)
    {
        $first_empty_line = -1;
        $last_badge_line = -1;
        $badge_insert_line = -1;
        $empty_line_after = true;
        foreach ($readme_lines as $line_number => $line) {
            if ($first_empty_line == -1 && empty(trim($line))) {
                $first_empty_line = $line_number;
            }
            // Is this line a badge?
            if (preg_match('/\[\!\[[A-Za-z0-9\s]+\]\(.*\)/', $line)) {
                $last_badge_line = $line_number;
                // Is this line the License badge?
                if (preg_match('/\[\!\[License]\(.*\)/', $line)) {
                    if ($line_number) {
                        $badge_insert_line = $line_number;
                    } else {
                        $badge_insert_line = 0;
                    }
                    $empty_line_after = false;
                }
            } else {
                if ($last_badge_line != -1) {
                    // We already found the badges, exit foreach.
                    break;
                } elseif ($line_number > $number_of_lines_to_search) {
                    // We've searched enough lines, exit foreach.
                    break;
                }
            }
        }
        if ($badge_insert_line === -1) {
            if ($last_badge_line !== -1) {
                // If we found badges, we'll insert this badge after the last badge.
                $badge_insert_line = $last_badge_line + 1;
            } elseif ($first_empty_line !== -1) {
                // If we didn't find any badges, we'll insert this badge at the first empty line.
                $badge_insert_line = $first_empty_line + 1;
            } else {
                // Final fallback: insert badge in the second line of the file.
                $badge_insert_line = 1;
            }
        }
        return [$badge_insert_line, $empty_line_after];
    }
}
