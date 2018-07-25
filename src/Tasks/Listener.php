<?php
namespace SeanKndy\Daemon\Tasks;

interface Listener {
    /**
     * Called just after Task forks in main thread
     *
     * @param Task $task Task forked
     *
     * @return void
     */
    public function onTaskStart(Task $task);

    /**
     * Called when Task process has exited and is being reaped
     *
     * @param Task $task Task forked
     * @param int $status Exit value of task
     *
     * @return void
     */
    public function onTaskExit(Task $task, int $status);
}
