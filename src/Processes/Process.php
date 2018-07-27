<?php
namespace SeanKndy\Daemon\Processes;

use SeanKndy\Daemon\Tasks\Task;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Process
{
    /**
     * @var EventDispather
     */
    protected $dispatcher;

    /**
     * @var Task
     */
    protected $task;

    /**
     * @var float
     */
    protected $startTime, $endTime;

    /**
     * Process ID
     *
     * @var int
     */
    protected $pid;

    /**
     * Exit status
     *
     * @var int
     */
    protected $exitStatus;

    public function __construct(Task $task, EventDispatcher $dispatcher) {
        $this->task = $task;
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Fork process and execute Task
     *
     * @throws RuntimeException
     * @return void
     */
    public function fork() {
        $this->setStartTime();
        $task->init();
        if (($pid = \pcntl_fork()) > 0) { // in parent
            $this->pid = $pid;
            $this->dispatcher->dispatch(Event::START, new Event($this));
        } else if ($pid == 0) { // child
            $retval = $this->task->run();
            exit($retval);
        } else {
            throw new \RuntimeException("Failed to fork child!");
        }
    }

    /**
     * Reap process if we can
     *
     * @return void
     */
    public function reap() {
        // reap task process if zombied
        if (($r = \pcntl_waitpid($this->pid, $status, WNOHANG)) > 0) {
            $this->exitStatus = $status;
            $this->setEndTime();
            $this->task->finish();
            $this->dispatcher->dispatch(Event::EXIT, new Event($this));
        } else if ($r < 0) {
            throw new \RuntimeException("pcntl_waitpid() returned error value for PID $pid");
        }
    }

    /**
     * Get Task
     *
     * @return Task
     */
    public function getTask() {
        return $this->task;
    }

    /**
     * Get Process ID
     *
     * @return int
     */
    public function getPid() {
        return $this->pid;
    }

    /**
     * Get exit status
     *
     * @return int
     */
    public function getExitStatus() {
        return $this->exitStatus;
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
     * Fork off into daemon, return new PID
     *
     * @return int
     */
    public static function daemonize(Daemon $daemon) {
        $pid = \pcntl_fork();
        if ($pid == -1) {
            throw new \RuntimeException("Failed to pcntl_fork()!");
        } else if ($pid) { // parent process
            exit(0);
        } else { // child
            $sid = \posix_setsid();

            \fclose(STDIN);
            \fclose(STDOUT);
            \fclose(STDERR);
            \chdir('/');

            $stdIn = \fopen('/dev/null', 'r');
            $stdOut = \fopen('/dev/null', 'w');
            $stdErr = \fopen('php://stdout', 'w');

            return \getmypid();
        }
    }
}
