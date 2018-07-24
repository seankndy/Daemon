<?php
namespace SeanKndy\Daemon;

abstract class Daemon
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

    public function setDaemonize($d) {
        $this->daemonize = $d;
    }

    public function setPidFile($file) {
        $this->pidFile = $file;
    }

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

    protected function loop() {
        while ($this->runLoop) {
            if (count($this->children) < $this->maxChildren) // don't run and get more tasks if we are at max already
                $this->run();

            if ($this->taskQueue->count() > 0) {
                while (count($this->children) <= $this->maxChildren && $this->taskQueue->count() > 0) {
                    $this->executeTask($this->taskQueue->dequeue());
                }
            }

            // reap dead children
            foreach ($this->children as $pid => $task) {
                if (($r = \pcntl_waitpid($pid, $status, WNOHANG)) > 0) {
                    $task->setEndTime();
                    $this->onChildExit($pid, $status, $task);

                    $this->log(LOG_INFO, "Child with PID $pid exited with status $status, runtime was " . sprintf("%.3f", $task->runtime()) . "ms");
                    unset($this->children[$pid]);
                } else if ($r < 0) {
                    $this->log(LOG_ERR, "pcntl_waitpid() returned error value for PID $pid");
                }
            }

            usleep($this->quietTime);
        }
    }

    protected function executeTask(Task $task) {
        $task->setStartTime();
        if (($pid = \pcntl_fork()) > 0) { // parent
            $this->children[$pid] = $task;
            $this->log(LOG_NOTICE, "Spawned child with PID $pid");
        } else if ($pid == 0) { // child
            try {
                $retval = $task->run();
            } catch (\Exception $e) {
                $this->log(LOG_ERR, "Child with PID " . getmypid() . " had system error occur: " . $e->getMessage());
            }
            exit($retval);
        } else {
            $this->log(LOG_ERR, "Failed to fork child!");
        }
    }

    /**
     * Queue a task to run later.  The queued function should return
     * the child process exit value.
     *
     * @param callable $task Function to run
     * @param mixed $cargo Any data to pass to task at runtime, also returned
     *       to onChildExit()
     *
     * @return void
     */
    protected function queueTask(Task $task) {
        $this->taskQueue->enqueue($task);
    }

    protected function log($code, $msg) {
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
     * Called when dead child is reaped.
     *
     * @param int $pid PID of child
     * @param int $status Exist status of child
     * @param Task $task Task
     *
     * @return void
     */
    abstract public function onChildExit($pid, $status, Task $task);
}
