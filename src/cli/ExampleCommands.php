<?php

namespace Updatinate\Cli;

class ExampleCommands extends \Robo\Tasks
{
    /**
     * Multiply two numbers together
     *
     * @command multiply
     */
    public function multiply($a, $b)
    {
        $model = new \Updatinate\Example($a);
        $result = $model->multiply($b);

        $this->io()->text("$a times $b is $result");
    }
}
