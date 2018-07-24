<?php
namespace SeanKndy\Daemon;

class Task {
    public static $IPC_PARENT = 0;
    public static $IPC_CLIENT = 1;

    protected $func;
    protected $context;
    protected $startTime, $endTime;
    protected $ipc;
    
    public function __construct(\Closure $func, $context = null, IPC $ipc = null) {
        $this->func = $func;
        $this->context = $context;
        $this->ipc = $ipc;
    }

    public function setStartTime($time = null) {
        if ($time == null) $time = \microtime(true);
        $this->startTime = $time;
    }

    public function setEndTime($time = null) {
        if ($time == null) $time = \microtime(true);
        $this->endTime = $time;
    }
    
    public function getStartTime() {
        return $this->startTime;
    }
    
    public function getEndTime() {
        return $this->endTime;
    }
    
    public function runtime() {
        return sprintf('%.5f', ($this->endTime-$this->startTime)/1000);
    }
    
    public function getIpc() {
        return $this->ipc;
    }

    public function getContext() {
        return $this->context;
    }
    
    public function run() {
        $func = $this->func;
        $retval = $func($this);
        return $retval;
    }
}
