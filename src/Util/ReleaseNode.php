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
        // If there's a simple template, try filling that in first & return if found.
        $release_node = $this->getViaTemplate($config, $remote, $major, $version);
        if (!empty($release_node)) {
            return ['', $release_node];
        }

        $release_node = $this->getViaAtom($config, $remote, $major, $version);
        if (!empty($release_node)) {
            return ['', $release_node];
        }

        return ['No information on release node.', ''];
    }

    protected function getViaTemplate($config, $remote, $major = '[0-9]', $version = false)
    {
        $release_node_template = $this->getProjectAttribute($config, $remote, 'release-node.template');
        if (empty($release_node_template)) {
            return '';
        }

        $upstream = $config->get("projects.$remote.upstream.project", null);
        $remote_repo = $this->createRemote($config, $upstream ?: $remote);

        if (!empty($version)) {
            if (!$remote_repo->has($version)) {
                throw new \Exception("$version is not a valid release.");
            }
        }
        else {
            $tag_prefix = $config->get("projects.$remote.upstream.tag-prefix", '');
            $major = $config->get("projects.$remote.upstream.major", $major);
            // TODO: We've lost the distinction of 'version' vs. 'tag' here.
            // e.g. in Pressflow6, '{version}' is '6.46' and '{tag}' would be
            // 'pressflow-4.46', but `latest` here returns '6.46.126'. We
            // add the 'pressflow' back by inserting it into the release node template.
            $version = $remote_repo->latest($major, $tag_prefix);
        }

        $release_node = str_replace('{version}', $version, $release_node_template);

        return $release_node;
    }

    protected function getViaAtom($config, $remote, $major, $version)
    {
        $atom_url = $this->getProjectAttribute($config, $remote, 'release-node.atom');
        if (empty($atom_url)) {
            return '';
        }
        // This only gets us the last ten releases, but that should be
        // enough for our purposes.
        $atom_contents = file_get_contents($atom_url);
        $releases = new \SimpleXMLElement($atom_contents);
        foreach ($releases->entry as $entry) {
            $url = $entry->link['href'];
            if ($this->stable($entry->title) && $this->matchesVersion($entry->title, $version)) {
                // TODO: Would it be useful to also expose the title and other metadata here?
                return $url;
            }
        }

        // TODO: Read more pages to find older versions?
        throw new \Exception("$version is not a recent release.");
    }

    protected function stable($title)
    {
        if (strstr($title, "WordPress") === false) {
            return false;
        }

        foreach ([' RC', ' Release Candidate', ' Beta', ' Alpha'] as $unstable) {
            if (strstr($title, $unstable) !== false) {
                return false;
            }
        }

        return true;
    }

    protected function matchesVersion($title, $version)
    {
        return empty($version) || (strstr($title, " $version ") !== false);
    }

    protected function getProjectAttribute($config, $remote, $name)
    {
        $upstream = $config->get("projects.$remote.upstream.project");
        $value = $config->get("projects.$remote.$name");
        if (empty($value) && !empty($upstream)) {
            $value = $config->get("projects.$upstream.$name");
        }
        return $value;
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
