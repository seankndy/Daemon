<?php
namespace SeanKndy\Daemon\Tasks;

interface Listener {
    /**
     * Called just after Task forks in main thread
     *
     * @param Task $task Task forked
     * @param int $pid PID of the forked Task
     *
     * @return void
     */
    public function onTaskStart(Task $task, int $pid);

    /**
     * Called when Task process has exited and is being reaped
     *
     * @param Task $task Task forked
     * @param int $pid PID of the Task
     * @param int $status Exit value of task
     *
     * @return void
     */
    public function onTaskExit(Task $task, int $pid, int $status);
}
