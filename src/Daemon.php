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
    protected $daemonize;

    public function __construct($name, $maxChildren = 100, $quietTime = 1000000, $syslog = true) {
        $this->name = $name;
        $this->maxChildren = $maxChildren;
        $this->pidFile = null;
        $this->runLoop = true;
        $this->quietTime = $quietTime;
        $this->taskQueue = [];
        $this->children = [];
        $this->syslog = $syslog;
        $this->daemonize = true;

        if ($syslog) {
            openlog($name, LOG_PID, LOG_LOCAL0);
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
            $this->pid = pcntl_fork();
            if ($this->pid == -1) {
                $this->log(LOG_ERR, "Failed to fork to a daemon");
                exit(1);
            } else if ($this->pid) {
                $this->log(LOG_INFO, "Became daemon with PID " . $this->pid);
                if ($this->pidFile) {
                    if (!($fp = @fopen($this->pidFile, 'w+'))) {
                        $this->log(LOG_ERR, "Failed to open/create PID file: " . $this->pidFile);
                        exit(1);
                    }
                    fwrite($fp, $this->pid);
                    fclose($fp);
                }
                if ($this->syslog) closelog();
                exit(0);
            }
            $sid = posix_setsid();

            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            chdir('/');

            $stdIn = fopen('/dev/null', 'r');
            $stdOut = fopen('/dev/null', 'w');
            $stdErr = fopen('php://stdout', 'w');
        }

        /* example of signal handling
        pcntl_signal(SIGTERM, array($this, "sigHandler"));
        pcntl_signal(SIGHUP,  array($this, "sigHandler"));
        pcntl_signal(SIGINT, array($this, "sigHandler"));
        pcntl_signal(SIGUSR1, array($this, "sigHandler"));
        */

        $this->loop();

        if ($this->syslog) closelog();
    }

    protected function loop() {
        while ($this->runLoop) {
            if (count($this->children) < $this->maxChildren) // don't run and get more tasks if we are at max already
                $this->run();

            if ($this->taskQueue) {
                reset($this->taskQueue);
                while (count($this->children) <= $this->maxChildren && list($taskidx) = each($this->taskQueue)) {
                    $this->executeTask($taskidx);
                }
            }

            // reap dead children
            foreach ($this->children as $pid => $startTime) {
                if (($r = pcntl_waitpid($pid, $status, WNOHANG)) > 0) {
                    $this->log(LOG_INFO, "Child with PID $pid exited with status $status, runtime was " . sprintf("%.3f", (microtime(true)-$startTime)/1000) . "ms");
                    unset($this->children[$pid]);
                } else if ($r < 0) {
                    $this->log(LOG_ERR, "pcntl_waitpid() returned error value for PID $pid");
                }
            }

            $this->taskQueue = array_values($this->taskQueue); // reindex

            usleep($this->quietTime);
        }
    }

    protected function executeTask($idx) {
        $task = $this->taskQueue[$idx];

        if (($pid = pcntl_fork()) > 0) { // parent
            unset($this->taskQueue[$idx]);

            $this->children[$pid] = microtime(true);
            $this->log(LOG_NOTICE, "Spawned child with PID $pid");
        } else if ($pid == 0) { // child
            $retval = $task();
            exit($retval);
        } else {
            $this->log(LOG_ERR, "Failed to fork child!");
        }
    }

    protected function queueTask(\Closure $task) {
        // push task onto stack
        $this->taskQueue[] = $task;
    }

    protected function log($code, $msg) {
        if ($this->syslog) {
            syslog($code, $msg);
        }
    }

    public function sigHandler($signo) {
        ; // implement your own signal handling
    }

    //
    // implement this function and call queueTask() to schedule tasks to be forked/ran.
    // this function should not loop endlessly/block, but should fire off queueTask()
    // when something needs forked/ran
    //
    abstract public function run();
}
