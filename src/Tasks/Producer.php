<?php
namespace SeanKndy\Daemon\Tasks;

interface Producer
{
    /**
     * Produce Task to run, or null if none
     *
     * @return Task|callable
     */
    public function produce();
}
