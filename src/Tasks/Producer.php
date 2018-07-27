<?php
namespace SeanKndy\Daemon\Tasks;

interface Producer {
    /**
     * Produce Task(s) to run, or null if none
     *
     * @return Task or array
     */
    public function produce();
}
