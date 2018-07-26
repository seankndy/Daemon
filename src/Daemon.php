<?php
namespace SeanKndy\Daemon;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;

abstract class Daemon implements EventSubscriberInterface
{
    /**
     * Currently running tasks
     *
     * @var array
     */
    protected $tasks;

    /**
     * Queue for tasks not yet running
     *
     * @var \SplQueue
     */
    protected $taskQueue;

    /**
     * Maximum number of tasks to run at once
     *
     * @var int
     */
    protected $maxTasks;

    /**
     * EventDispatcher
     *
     * @var EventDispatcher
     */
    protected $dispatcher;

    /**
     * PID of daemon process
     *
     * @var int
     */
    protected $pid;

    /**
     * PID filename
     *
     * @var string
     */
    protected $pidFile;

    /**
     * Name of this process/daemon
     *
     * @var string
     */
    protected $name;

    /**
     * Log to Syslog?
     *
     * @var bool
     */
    protected $syslog;

    /**
     * Should main loop run
     *
     * @var bool
     */
    protected $runLoop;

    /**
     * Time to wait between loop iterations (microsec)
     *
     * @var int
     */
    protected $quietTime;

    /**
     * Daemonize to background or not
     *
     * @var bool
     */
    protected $daemonize;

    public function __construct($name, $maxTasks = 100, $quietTime = 1000000, $syslog = true) {
        $this->name = $name;
        $this->maxTasks = $maxTasks;
        $this->pidFile = null;
        $this->runLoop = true;
        $this->quietTime = $quietTime;
        $this->taskQueue = new \SplQueue();
        $this->tasks = [];
        $this->syslog = $syslog;
        $this->daemonize = true;

        // setup event dispatcher
        $this->dispatcher = new EventDispatcher();
        $this->addSubscriber($this);

        if ($syslog) {
            \openlog($name, LOG_PID, LOG_LOCAL0);
        }
    }

    /**
     * EventSubscriberInterface Implementation
     *
     * @return array
     */
    public static function getSubscribedEvents() {
        return [
            Tasks\Event::START => 'onTaskStart',
            Tasks\Event::START => 'onTaskStop',
            // we want this to run after any user-defined events
            Tasks\Event::ITERATION => ['onTaskIteration', -100]
        ];
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
            if (count($this->tasks) < $this->maxTasks) // don't run and get more tasks if we are at max already
                $this->run();

            // look for queued work, execute it
            if ($this->taskQueue->count() > 0) {
                while (count($this->tasks) <= $this->maxTasks && $this->taskQueue->count() > 0) {
                    $task = $this->taskQueue->dequeue();

                    try {
                        $task->start();
                    } catch (\Exception $e) {
                        $this->log(LOG_ERR, "Failed to start Task: " . $e->getMessage());
                    }
                }
            }

            // call onIterate() for each Task
            foreach ($this->tasks as $pid => $task) {
                try {
                    $task->onIterate();
                } catch (\Exception $e) {
                    $this->log(LOG_ERR, "Task onIterate() failed for PID $pid: " . $e->getMessage());
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
     * Record task in children array
     *
     * @param Tasks\Event Event object
     *
     * @return void
     */
    public function onTaskStart(Tasks\Event $event) {
        $task = $event->getTask();
        $this->tasks[$task->getPid()] = $task;
    }

    /**
     * Remove task from children array
     *
     * @param Tasks\Event Event object
     *
     * @return void
     */
    public function onTaskExit(Tasks\Event $event) {
        $task = $event->getTask();
        unset($this->tasks[$task->getPid()]);
    }
}
