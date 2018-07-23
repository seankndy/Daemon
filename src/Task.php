<?php
namespace SeanKndy\Daemon;

class Task {
    protected $func;
    protected $context;
    protected $startTime, $endTime;
    
    public function __construct(\Closure $func, $context = null) {
        $this->func = $func;
        $this->context = $context;
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
    
    public function getContext() {
        return $this->context;
    }
    
    public function run() {
        $func = $this->func;
        $retval = $func($this->context);
        return $retval;
    }
}
