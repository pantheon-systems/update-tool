<?php
namespace UpdateTool\Util;


/**
 * Support level related functions.
 */
class SupportLevel {

    /**
     * Get right badge markdown.
     */
    public static function getSupportLevelBadge($level)
    {
        $badges = self::getSupportLevelBadges();
        return $badges[$level] ?? '';
    }

    /**
     * Get all support level badges.
     */
    public static function getSupportLevelBadges()
    {
        return [
            'ea' => '[![Early Access](https://img.shields.io/badge/pantheon-EARLY_ACCESS-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/early-access?q=org%3Apantheon-systems)',
            'la' => '[![Limited Availability](https://img.shields.io/badge/pantheon-LIMITED_AVAILABILTY-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/limited-availability?q=org%3Apantheon-systems)',
            'actively-supported' => '[![Actively Maintained](https://img.shields.io/badge/pantheon-actively_maintained-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/actively-maintained?q=org%3Apantheon-systems)',
            'minimally-supported' => '[![Minimal Support](https://img.shields.io/badge/pantheon-minimal_support-yellow?logo=pantheon&color=FFDC28&style=for-the-badge)](https://github.com/topics/minimal-support?q=org%3Apantheon-systems)',
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
        return [
            'ea' => 'Early Access',
            'la' => 'Limited Availability',
            'actively-supported' => 'Actively Maintained',
            'minimally-supported' => 'Minimal Support',
            'unsupported' => 'Unsupported',
            'unofficial' => 'Unofficial',
            'deprecated' => 'Deprecated',
        ];
    }

    /**
     * Get label for given badge.
     */
    public static function getSupportLevelLabel($level)
    {
        $badges = self::getBadgesLabels();
        return $badges[$level] ?? '';
    }

}