<?php
namespace SeanKndy\Daemon\Tasks;

interface Producer {
    /**
     * Produce Task to run, or null if none
     *
     * @return Task
     */
    public function produce();
}
