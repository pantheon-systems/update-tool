<?php
namespace UpdateTool\Util;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * ComposerJson is a convenience class for manipulating composer.json files.
 */
class JsonFile
{
        /** @var string */
    protected $path = '';

        /** @var array */
    protected $data = [];

    public function __construct($path = '')
    {
        $this->path = $path;

        if ($this->exists()) {
            $this->read();
        }
    }

    public function exists()
    {
        return is_file($this->path);
    }

    public function revert()
    {
        return $this->read();
    }

    public function write()
    {
        $this->ensureParentExists();
        $contents = json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->path, $contents);
    }

    protected function mustHavePath()
    {
        if (empty($this->path)) {
            throw new \RuntimeException("No path defined for json file");
        }
    }

    protected function mustExist()
    {
        $this->mustHavePath();
        if (!$this->exists()) {
            throw new \RuntimeException("Json file does not exist at '{$this->path}'");
        }
    }

    protected function read()
    {
        $this->mustExist();
        $contents = file_get_contents($this->path);
        $this->data = json_decode($contents, true);
    }

    protected function ensureParentExists()
    {
        $this->mustHavePath();
        $fs = new Filesystem();
        $fs->mkdir(\dirname($this->path));
    }
}
