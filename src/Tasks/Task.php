<?php
namespace SeanKndy\Daemon\Tasks;

use SeanKndy\Daemon\Daemon;

abstract class Task {
    /**
     * @var SeanKndy\Daemon\Daemon
     */
    protected $daemon;

    /**
     * @var mixed
     */
    protected $context;

    /**
     * @var float
     */
    protected $startTime, $endTime;

    /**
     * @var int
     */
    protected $pid;

    /**
     * @var Listener
     */
    protected $listener;

    /**
     * Constructor, always called from main process thread
     *
     * @param mixed $context Any object for use when $func executes
     * @param Listener $listener Listener object for Task
     *
     * @return $this
     */
    public function __construct(Daemon $daemon, $context = null, Listener $listener) {
        $this->daemon = $daemon;
        $this->context = $context;
        $this->listener = $listener;
    }

    /**
     * Set start time
     *
     * @param float Microtime
     *
     * @return $this
     */
    public function setStartTime($time = null) {
        if ($time == null) $time = \microtime(true);
        $this->startTime = $time;
        return $this;
    }

    /**
     * Get start time
     *
     * @return float
     */
    public function getStartTime() {
        return $this->startTime;
    }

    /**
     * Set end time
     *
     * @param float Microtime
     *
     * @return $this
     */
    public function setEndTime($time = null) {
        if ($time == null) $time = \microtime(true);
        $this->endTime = $time;
        return $this;
    }

    /**
     * Get end time
     *
     * @return float
     */
    public function getEndTime() {
        return $this->endTime;
    }

    /**
     * Calclate runtime in milliseconds
     *
     * @return float
     */
    public function runtime() {
        return sprintf('%.5f', ($this->endTime-$this->startTime)/1000);
    }

    /**
     * Get context
     *
     * @return mixed
     */
    public function getContext() {
        return $this->context;
    }

    /**
     * Initialize task (main thread)
     *
     * @return void
     */
    abstract public function init() : void;

    /**
     * Do the work
     *
     * @return int
     */
    abstract public function run() : int;

    /**
     * Setup Task, then fork and return child PID
     *
     * @return int
     */
    public function start() {
        $this->setStartTime();

        $this->init();

        if (($pid = \pcntl_fork()) > 0) { // parent
            if ($this->listener) {
                $this->listener->onTaskStart($this, $pid);
            }

            return $pid;
        } else if ($pid == 0) { // child
            $retval = $this->run();
            exit($retval);
        } else {
            throw new \RuntimeException("Failed to fork child!");
        }
    }

    /**
     * Parent check-in with running task
     *
     * @return void
     */
    public function checkIn() {
        // has this task process died yet?
        if (($r = \pcntl_waitpid($this->pid, $status, WNOHANG)) > 0) {
            $this->end($pid, $status);
        } else if ($r < 0) {
            throw new \RuntimeException("pcntl_waitpid() returned error value for PID {$this->pid}");
        }
    }

    /**
     * Complete-out task
     *
     * @return void
     */
    public function end(int $pid, int $status) {
        $this->setEndTime();

        if ($this->listener) {
            $this->listener->onTaskExit($this, $pid, $status);
        }
    }
}
