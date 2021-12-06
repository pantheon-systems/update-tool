<?php

namespace UpdateTool\Util;

/**
 * Support level related functions.
 */
class SupportLevel
{

    const SUPPORT_LEVEL_BADGE_LABEL_REGEX = '/^\[\!\[([A-Za-z\s\d]+)\]\(https:\/\/img.shields.io/';

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
            'ea' => '[![Early Access](https://img.shields.io/badge/pantheon-EARLY_ACCESS-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/early-access?q=org%3Apantheon-systems)',
            'la' => '[![Limited Availability](https://img.shields.io/badge/pantheon-LIMITED_AVAILABILTY-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/limited-availability?q=org%3Apantheon-systems)',
            'active' => '[![Actively Maintained](https://img.shields.io/badge/pantheon-actively_maintained-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/actively-maintained?q=org%3Apantheon-systems)',
            'minimal' => '[![Minimal Support](https://img.shields.io/badge/pantheon-minimal_support-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/minimal-support?q=org%3Apantheon-systems)',
            'unsupported' => '[![Unsupported](https://img.shields.io/badge/pantheon-unsupported-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/unsupported?q=org%3Apantheon-systems)',
            'unofficial' => '[![Unofficial](https://img.shields.io/badge/pantheon-unofficial-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/unofficial?q=org%3Apantheon-systems)',
            'deprecated' => '[![Deprecated](https://img.shields.io/badge/pantheon-deprecated-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/unofficial?q=org%3Apantheon-systems)',
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
        return null;
    }

    /**
     * Compare support label from readme and badge.
     */
    public static function compareSupportLevelFromReadmeAndBadge($readme_contents, $badge_contents)
    {
        $support_levels_from_readme = self::getSupportLevelsFromContent($readme_contents);
        $support_levels_from_badge = self::getSupportLevelsFromContent($badge_contents);
        return (bool) count(array_intersect($support_levels_from_readme, $support_levels_from_badge));
    }
}
