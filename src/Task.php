<?php
namespace SeanKndy\Daemon;

class Task {
    protected $func;
    protected $context;
    protected $startTime, $endTime;
    
    public function __construct(\Closure $func, $context = null) {
        $this->func = $func;
        $this->func->bindTo($this);
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
        $retval = $func();
        return $retval;
    }
    
    public static function putSharedMemory(string $data) {
        if (($shm = @\shmop_open(getmypid(), 'c', 0644, strlen($data))) === false) {
            throw new \RuntimeException("shmop_open() failed!");
        }
        if (@\shmop_write($shm, $data, 0) != strlen($data)) {
            throw new \RuntimeException("shmop_write() failed!");
        }
    }

    public static function getSharedMemory(int $pid) {
        if (($shm = @\shmop_open($pid, "a", 0, 0)) === false) {
            throw new \RuntimeException("shmop_open() failed!");
        }
        if (($data = @\shmop_read($shm, 0, \shmop_size($shm))) !== false) {
            @\shmop_delete($shm);
            @\shmop_close($shm);

            return $data;
        } else {
            throw new \RuntimeException("shmop_read() failed!");
        }
    }    
}
