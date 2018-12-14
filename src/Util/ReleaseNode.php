<?php
namespace Updatinate\Util;

use Consolidation\Config\ConfigInterface;
use Updatinate\Git\Remote;

/**
 * ReleaseNote facilitates looking up the URL of the page that describes
 * a given upstream release.
 *
 * LIMITATION:
 *
 * Only the latest release can be obtained with this class.
 *
 * There are two ways to look up a release node:
 *
 * 1. Via url and regex
 *    - URL points to a page that contains a link to the release node
 *    - regex provides a pattern to search for on the page
 * 2. Via template
 *    - Template contains the link to the release node with a placeholder for the version
 *    - The version is looked up via Remote::latest
 *
 * Example configuration for url and regex:
 *
 *   drupal:
 *     release-node:
 *       url: https://www.drupal.org/project/drupal
 *       pattern: '"(https://www.drupal.org/project/drupal/releases/{major}[0-9.]*)"'
 *
 * Example configuration for template:
 *
 *   drupal:
 *     repo: git://git.drupal.org/project/drupal.git
 *     release-node:
 *       template: 'https://www.drupal.org/project/drupal/releases/{version}'
 *
 * Note also that it is also possible to provide the release node information
 * indirectly via an 'upstream' property that indicates the project that
 * contains the release information:
 *
 *   drops-8:
 *     upstream:
 *       project: drupal
 *       major: 8
 *
 */
class ReleaseNode
{
    protected $api;

    public function __construct($api)
    {
        $this->api = $api;
    }

    /**
     * get fetches the URL to the release node.
     *
     * @param Config $config
     * @param string $remote Name of the remote project to fetch the release node for
     * @return [string, string] Failure message (empty for success), release node page URL
     */
    public function get(ConfigInterface $config, $remote, $major = '[0-9]', $version = false)
    {
        list($release_node_template, $release_node_url, $release_node_pattern) = $this->info($config, $remote, $major, $version);

        if (empty($release_node_template) && empty($release_node_url)) {
            return ['The specified project does not exist, or does not define information on how to obtain the release node.', ''];
        }

        if (!empty($release_node_template)) {
            return ['', $release_node_template];
        }

        $release_node = $this->pageUrl($release_node_url, $release_node_pattern);

        if (empty($release_node)) {
            return ['No information on release node.', ''];
        }

        // TODO: Try to GET the release node, and return an error if there's nothing there

        return ['', $release_node];
    }

    protected function info($config, $remote, $major = '[0-9]', $version = false)
    {
        // Get the tag prefix for our upstream before switching '$remote'.
        $tag_prefix = $config->get("projects.$remote.upstream.tag-prefix", '');

        if (!$config->has("projects.$remote.release-node.url") && $config->has("projects.$remote.upstream.project")) {
            $remote = $config->get("projects.$remote.upstream.project");
            $major = $config->get("projects.$remote.upstream.major", $major);
        }
        $release_node_url = $config->get("projects.$remote.release-node.url");
        $release_node_pattern = $config->get("projects.$remote.release-node.pattern");
        $release_node_template = $config->get("projects.$remote.release-node.template");

        $release_node_pattern = str_replace('{major}', $major, $release_node_pattern);

        if (!empty($release_node_template) && empty($version)) {
            $remote_repo = $this->createRemote($config, $remote);
            // TODO: We've lost the distinction of 'version' vs. 'tag' here.
            // e.g. in Pressflow6, '{version}' is '6.46' and '{tag}' would be
            // 'pressflow-4.46', but `latest` here returns '6.46.126'. We
            // add the 'pressflow' back by inserting it into the release node template.
            $version = $remote_repo->latest($major, $tag_prefix);
        }

        $release_node_template = str_replace('{version}', $version, $release_node_template);

        return [$release_node_template, $release_node_url, $release_node_pattern];
    }

    protected function pageUrl($release_url, $pattern)
    {
        $client = new \GuzzleHttp\Client();
        $res = $client->request('GET', $release_url);
        if ($res->getStatusCode() != 200) {
            return '';
        }
        $page = $res->getBody();
        if (!preg_match("#$pattern#", $page, $matches)) {
            return false;
        }
        return $matches[1];
    }

    protected function createRemote($config, $remote_name)
    {
        $remote_url = $config->get("projects.$remote_name.repo");
        return Remote::create($remote_url, $this->api);
    }
}
