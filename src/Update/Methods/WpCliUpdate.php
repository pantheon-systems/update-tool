<?php

namespace Updatinate\Update\Methods;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Updatinate\Git\WorkingCopy;
use Updatinate\Util\TmpDir;
use Updatinate\Util\ExecWithRedactionTrait;
use Consolidation\Config\ConfigInterface;

/**
 * WpCliUpdate will take the provided WorkingCopy of the original project
 * and use wp-cli to update it to the latest version.
 */
class WpCliUpdate implements UpdateMethodInterface, LoggerAwareInterface
{
    use UpdateMethodTrait;
    use LoggerAwareTrait;
    use ExecWithRedactionTrait;

    protected $version_check_url;
    protected $version;

    protected $dbhost;
    protected $dbname;
    protected $dbuser;
    protected $dbpw;
    protected $url;
    protected $title;
    protected $admin;
    protected $adminPw;
    protected $adminEmail;

    /**
     * @inheritdoc
     */
    public function configure(ConfigInterface $config, $project)
    {
        $upstream = $config->get("projects.$project.upstream.project");
        $this->version_check_url = $config->get("projects.$upstream.version-api.url", 'https://api.wordpress.org/core/version-check/1.7/');

        $this->dbhost = $config->get("fixtures.mysql.host", '127.0.0.1');
        $this->dbuser = $config->get("fixtures.mysql.user", 'root');
        $this->dbpw = $config->get("fixtures.mysql.pw", '');
        $this->dbname = $config->get("fixtures.wp.dbname", 'updatinate-wp-db');
        $this->url = $config->get("fixtures.wp.url", 'updatinate-site');
        $this->title = $config->get("fixtures.wp.title", 'Updatinate Site');
        $this->admin = $config->get("fixtures.wp.admin", 'admin');
        $this->adminPw = $config->get("fixtures.wp.admin-pw", 'manticore');
        $this->adminEmail = $config->get("fixtures.wp.admin-email", 'bot@pantheon.io');
    }

    /**
     * @inheritdoc
     */
    public function findLatestVersion($major, $tag_prefix, $update_parameters)
    {
        $availableVersions = file_get_contents($this->version_check_url);
        if (empty($availableVersions)) {
            throw new \Exception('Could not contact the WordPress version-check API endpoint.');
        }
        $versionData = json_decode($availableVersions, true);
        if (!isset($versionData['offers'][0])) {
            throw new \Exception('No offers returned from the WordPress version-check API endpoint.');
        }
        $version = $versionData['offers'][0]['version'];
        $this->version = $version;

        return $version;
    }

    /**
     * @inheritdoc
     */
    public function update(WorkingCopy $originalProject, array $parameters)
    {
        $path = $originalProject->dir();

        $wpConfigPath = "$path/wp-config.php";
        $wpConfigData = file_get_contents($wpConfigPath);
        unlink($wpConfigPath);

        try {
            // Set up a local WordPress site
            $this->wpCoreConfig($path, $this->dbhost, $this->dbname, $this->dbuser, $this->dbpw);
            $this->wpDbDropIfNotCI($path);
            $this->wpDbCreate($path);
            $this->wpCoreInstall($path, $this->url, $this->title, $this->admin, $this->adminPw, $this->adminEmail);

            // Tell wp-cli to go do the update; check and make sure the checksums are okay
            $this->wpCoreUpdate($path, $this->version);
            $this->wpCoreVerifyChecksums($path);
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->wpDbDropIfNotCI($path);
            file_put_contents($wpConfigPath, $wpConfigData);
        }
        return $originalProject;
    }

    /**
     * @inheritdoc
     */
    public function postCommit(WorkingCopy $updatedProject, array $parameters)
    {
    }

    /**
     * @inheritdoc
     */
    public function complete(array $parameters)
    {
    }

    /**
     * Call the wp-cli 'core config' command to set up our wp-config.php file.
     */
    protected function wpCoreConfig($path, $dbhost, $dbname, $dbuser, $dbpw)
    {
        // wp core config --dbname=wp --dbuser=mywp --dbpass=mywp
        return $this->wp(
            $path,
            'core config',
            [
                "--dbhost=$dbhost",
                "--dbname=$dbname",
                "--dbuser=$dbuser",
                "--dbpass=$dbpw",
            ]
        );
    }

    /**
     * Create a database for us to work with
     */
    protected function wpDbCreate($path)
    {
        return $this->wp($path, 'db create');
    }

    /**
     * Call 'db drop', but only if not running on a CI server
     */
    protected function wpDbDropIfNotCI($path)
    {
        if (!getenv('CI')) {
            $this->wpDbDrop($path);
        }
    }

    /**
     * Drop any existing database to clean up after previous aborted
     * runs / at the end of the current run.
     */
    protected function wpDbDrop($path)
    {
        return $this->wpcliReturnStatus($path, 'db drop', ['--yes']);
    }

    /**
     * Use wp-cli to install WordPress. The update function only works
     * on an installed site.
     */
    protected function wpCoreInstall($path, $url, $title, $admin, $pw, $email)
    {
        // wp core install --url=your_domain --title=Your_Blog_Title --admin_user=username --admin_password=password --admin_email=your_email.com
        return $this->wp(
            $path,
            'core install',
            [
                "--url=$url",
                "--title='$title'",
                "--admin_user=$admin",
                "--admin_password=$pw",
                "--admin_email=$email",
            ]
        );
    }

    /**
     * Use wp-cli to update to the specified version.
     */
    protected function wpCoreUpdate($path, $version)
    {
        return $this->wp(
            $path,
            'core update',
            ["--version=$version"]
        );
    }

    /**
     * Use wp-cli to verify the checksums of the installed release to
     * ensure it is valid. (This is a great feature.)
     */
    protected function wpCoreVerifyChecksums($path)
    {
        return $this->wp(
            $path,
            'core verify-checksums'
        );
    }

    /**
     * Run wp-cli and return the status code from the result of the operation.
     */
    protected function wpcliReturnStatus($path, $command, $args = [])
    {
        passthru("wp --path=$path $command " . implode(' ', $args), $status);
        return $status;
    }

    /**
     * Call wp-cli and throw an exception if the operation fails.
     */
    protected function wp($path, $command, $args = [])
    {
        $this->logger->notice("wp $command " . implode(' ', $args));
        $status = $this->wpcliReturnStatus($path, $command, $args);
        if ($status) {
            throw new \Exception("wp-cli command '$command' failed with exit code $status");
        }
    }
}
