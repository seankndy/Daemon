<?php
namespace SeanKndy\Daemon;

class Task {
    /**
     * @var Closure
     */
    protected $func;

    /**
     * @var mixed
     */
    protected $context;

    /**
     * @var float
     */
    protected $startTime, $endTime;

    /**
     * @var IPC
     */
    protected $ipc;

    /**
     * Constructor, always called from main process thread
     *
     * @param Closure $func Function that runs task
     * @param mixed $context Any object for use when $func executes
     * @param IPC $ipc IPC object for communication with parent process
     *
     * @return $this
     */
    public function __construct(\Closure $func, $context = null, IPC $ipc = null) {
        $this->func = $func;
        $this->context = $context;
        $this->ipc = $ipc;
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
     * Set IPC for this task
     *
     * @param IPC $ipc IPC object
     *
     * @return $this
     */
    public function setIpc(IPC $ipc) {
        $this->ipc = $ipc;
        return $this;
    }

    /**
     * Get IPC for this task
     *
     * @return IPC
     */
    public function getIpc() {
        return $this->ipc;
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
     * Execute task closure, return integer exit value
     *
     * @return int
     */
    public function run() {
        $func = $this->func;
        $retval = $func($this);
        return $retval;
    }
}
