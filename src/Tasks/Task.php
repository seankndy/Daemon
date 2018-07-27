<?php
namespace SeanKndy\Daemon\Tasks;

use SeanKndy\Daemon\Daemon;

interface Task {
    /**
     * Initialize task (main thread)
     *
     * @return void
     */
    public function init(Daemon $daemon) : void;

    /**
     * Do the work (child thread)
     *
     * @return int
     */
     public function run() : int;

     /**
      * Cleanup (main thread)
      *
      * @return void
      */
     public function finish();
}
