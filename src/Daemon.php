<?php
namespace SeanKndy\Daemon;

abstract class Daemon implements Tasks\Listener
{
    protected $maxChildren;
    protected $children;
    protected $pid;
    protected $pidFile;
    protected $name;
    protected $syslog;
    protected $runLoop;
    protected $quietTime;
    protected $taskQueue;
    protected $daemonize;

    public function __construct($name, $maxChildren = 100, $quietTime = 1000000, $syslog = true) {
        $this->name = $name;
        $this->maxChildren = $maxChildren;
        $this->pidFile = null;
        $this->runLoop = true;
        $this->quietTime = $quietTime;
        $this->taskQueue = new \SplQueue();
        $this->children = [];
        $this->syslog = $syslog;
        $this->daemonize = true;

        if ($syslog) {
            \openlog($name, LOG_PID, LOG_LOCAL0);
        }
    }

    /**
     * Set daemonize flag
     *
     * @param boolean $d
     *
     * @return $this
     */
    public function setDaemonize($d) {
        $this->daemonize = $d;
        return $this;
    }

    /**
     * Set PID file
     *
     * @param string $file File name
     *
     * @return $this
     */
    public function setPidFile($file) {
        $this->pidFile = $file;
        return $this;
    }

    /**
     * Start daemon
     *
     * @return void
     */
    public function start() {
        if ($this->daemonize) {
            $this->pid = \pcntl_fork();
            if ($this->pid == -1) {
                $this->log(LOG_ERR, "Failed to fork to a daemon");
                exit(1);
            } else if ($this->pid) {
                $this->log(LOG_INFO, "Became daemon with PID " . $this->pid);
                if ($this->pidFile) {
                    if (!($fp = @\fopen($this->pidFile, 'w+'))) {
                        $this->log(LOG_ERR, "Failed to open/create PID file: " . $this->pidFile);
                        exit(1);
                    }
                    \fwrite($fp, $this->pid);
                    \fclose($fp);
                }
                if ($this->syslog) \closelog();
                exit(0);
            }
            $sid = \posix_setsid();

            \fclose(STDIN);
            \fclose(STDOUT);
            \fclose(STDERR);
            \chdir('/');

            $stdIn = \fopen('/dev/null', 'r');
            $stdOut = \fopen('/dev/null', 'w');
            $stdErr = \fopen('php://stdout', 'w');
        }

        /* example of signal handling
        \pcntl_signal(SIGTERM, array($this, "sigHandler"));
        \pcntl_signal(SIGHUP,  array($this, "sigHandler"));
        \pcntl_signal(SIGINT, array($this, "sigHandler"));
        \pcntl_signal(SIGUSR1, array($this, "sigHandler"));
        */

        $this->loop();

        if ($this->syslog) \closelog();
    }

    /**
     * Main work loop
     *
     * @return void
     */
    protected function loop() {
        while ($this->runLoop) {
            // execute concrete run() implemenation, which will queue
            // work needed done.
            if (count($this->children) < $this->maxChildren) // don't run and get more tasks if we are at max already
                $this->run();

            // look for queued work, execute it
            if ($this->taskQueue->count() > 0) {
                while (count($this->children) <= $this->maxChildren && $this->taskQueue->count() > 0) {
                    $task = $this->taskQueue->dequeue();

                    try {
                        $task->start();
                    } catch (\Exception $e) {
                        $this->log(LOG_ERR, "Failed to start Task: " . $e->getMessage());
                    }
                }
            }

            // trigger task events and cleanup tasks
            foreach ($this->children as $pid => $task) {
                try {
                    $task->checkIn();
                } catch (\Exception $e) {
                    $this->log(LOG_ERR, "Task check in failed for PID $pid: " . $e->getMessage());
                }
            }

            usleep($this->quietTime);
        }
    }

    /**
     * Queue a task to run later.  The queued function should return
     * the child process exit value.
     *
     * @param Task $task Task to run async
     *
     * @return void
     */
    public function queueTask(Tasks\Task $task) {
        $this->taskQueue->enqueue($task);
    }

    /**
     * Log message to syslog
     *
     * @return void
     */
    public function log($code, $msg) {
        if ($this->syslog) {
            \syslog($code, $msg);
        }
    }

    public function sigHandler($signo) {
        ; // implement your own signal handling
    }

    /**
     * Implement this function and call queueTask() to schedule tasks to be forked/ran.
     * This function should not loop endlessly/block, but should fire off queueTask()
     * when something needs executed.
     *
     * @return void
     */
    abstract public function run();

    /**
     * Tasks\Listener Implementation
     * Record task in children array
     *
     * @param Task $task Task forked
     * @param int $pid PID of the forked Task
     *
     * @return void
     */
    public function onTaskStart(Tasks\Task $task) {
        $this->children[$task->getPid()] = $task;
    }

    /**
     * Tasks\Listener Implementation
     * Remove task from children array
     *
     * @param Task $task Task forked
     * @param int $pid PID of the Task
     * @param int $status Exit value of task
     *
     * @return void
     */
    public function onTaskExit(Tasks\Task $task, int $status) {
        unset($this->children[$task->getPid()]);
    }
}
