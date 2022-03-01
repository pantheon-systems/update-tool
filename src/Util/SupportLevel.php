<?php

namespace UpdateTool\Util;

/**
 * Support level related functions.
 */
class SupportLevel
{

    const SUPPORT_LEVEL_BADGE_LABEL_REGEX = '/^\[\!\[([A-Za-z\s\d]+)\]\(https:\/\/img.shields.io/';
    const CURRENT_SUPPORT_LEVEL_BADGE_LABEL_REGEX = '/^\[\!\[NAME\]\(https:\/\/img\.shields\.io.*$/m';

    /**
     * Get right badge markdown.
     */
    public static function getSupportLevelBadge($level)
    {
        $badges = self::getSupportLevelBadges();
        $badge_contents = $badges[$level] ?? '';
        if (!$badge_contents) {
            // Try badge label.
            $labels = self::getBadgesLabels();
            foreach ($labels as $badge_name => $label) {
                if (strpos($label, $level) !== false) {
                    $badge_contents = $badges[$badge_name];
                    break;
                }
            }
        }
        return $badge_contents;
    }

    /**
     * Get all support level badges.
     */
    public static function getSupportLevelBadges()
    {
        return [
            'ea' => '[![Early Access](https://img.shields.io/badge/Pantheon-Early_Access-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#early-access)',
            'la' => '[![Limited Availability](https://img.shields.io/badge/Pantheon-Limited_Availability-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#limited-availability)',
            'active' => '[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained)',
            'minimal' => '[![Minimal Support](https://img.shields.io/badge/Pantheon-Minimal_Support-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#minimal-support)',
            'unsupported' => '[![Unsupported](https://img.shields.io/badge/Pantheon-Unsupported-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#unsupported)',
            'unofficial' => '[![Unofficial](https://img.shields.io/badge/Pantheon-Unofficial-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#unofficial)',
            'deprecated' => '[![Deprecated](https://img.shields.io/badge/Pantheon-Deprecated-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#deprecated)',
        ];
    }

    /**
     * Get badges human names.
     */
    public static function getBadgesLabels()
    {
        $badges = self::getSupportLevelBadges();
        $labels = [];
        foreach ($badges as $key => $badge) {
            preg_match(self::SUPPORT_LEVEL_BADGE_LABEL_REGEX, $badge, $matches);
            if (!empty($matches[1])) {
                $labels[$key] = $matches[1];
            }
        }
        return $labels;
    }

    /**
     * Get label for given badge.
     */
    public static function getSupportLevelLabel($level)
    {
        $badges = self::getBadgesLabels();
        return $badges[$level] ?? '';
    }

    /**
     * Get support level from README.md contents.
     */
    public static function getSupportLevelsFromContent($contents, $return_only_first = false)
    {
        $support_level = null;
        $lines = explode("\n", $contents);
        $badges = SupportLevel::getSupportLevelBadges();
        $support_levels = [];
        foreach ($lines as $line) {
            foreach ($badges as $key => $badge) {
                // Get the badge text from the badge markup.
                preg_match(self::SUPPORT_LEVEL_BADGE_LABEL_REGEX, $badge, $matches);
                if (!empty($matches[1])) {
                    if (strpos($line, $matches[1]) !== false) {
                        $support_levels[$key] = $key;
                    }
                }
            }
        }
        if ($support_levels) {
            foreach ($support_levels as $key => $support_level) {
                $support_levels[$key] = self::getSupportLevelLabel($support_level);
            }
            if ($return_only_first) {
                return reset($support_levels);
            }
            return $support_levels;
        }
        return [];
    }

    /**
     * Compare support label from readme and badge.
     */
    public static function compareSupportLevelFromReadmeAndBadge($readme_contents, $badge_contents)
    {
        $support_levels_from_readme = self::getSupportLevelsFromContent($readme_contents);
        $support_levels_from_badge = self::getSupportLevelsFromContent($badge_contents);

        return count($support_levels_from_readme) === 1 && count(array_intersect($support_levels_from_readme, $support_levels_from_badge));
    }

    /**
     * Delete all support badges in README but the one given.
     */
    public static function deleteSupportLevelBadgesFromReadme(&$readme_contents, $preserve_badge = null)
    {
        $support_levels_from_readme = self::getSupportLevelsFromContent($readme_contents);
        foreach ($support_levels_from_readme as $support_level) {
            if ($support_level !== $preserve_badge) {
                $pattern = str_replace('NAME', $support_level, self::CURRENT_SUPPORT_LEVEL_BADGE_LABEL_REGEX);
                // Delete support level badge.
                $return = preg_replace($pattern, '', $readme_contents);
                if ($return) {
                    $readme_contents = $return;
                    return true;
                }
            }
        }
        return false;
    }
}
