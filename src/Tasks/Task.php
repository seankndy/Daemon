<?php
namespace SeanKndy\Daemon\Tasks;

use SeanKndy\Daemon\Daemon;

interface Task
{
    /**
     * Initialize task (main thread)
     *
     * @return void
     */
    public function init() : void;

    /**
     * Do the work (child thread)
     *
     * @return int
     */
    public function run() : int;

    /**
     * Cleanup (main thread)
     *
     * @var int $status Exit status of child thread
     * @return void
     */
    public function finish(int $status) : void;
}
