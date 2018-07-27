<?php
namespace SeanKndy\Daemon;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;

class Daemon implements EventSubscriberInterface
{
    /**
     * Currently running processes
     *
     * @var array
     */
    protected $processes;

    /**
     * Queue for tasks not yet running
     *
     * @var \SplQueue
     */
    protected $processQueue;

    /**
     * Maximum number of tasks to run at once
     *
     * @var int
     */
    protected $maxProcesses;

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

    /**
     * Task Producers
     *
     * @var array
     */
    protected $producers;

    public function __construct($name, $maxProcesses = 100, $quietTime = 1000000, $syslog = true) {
        $this->name = $name;
        $this->maxProcesses = $maxProcesses;
        $this->pidFile = null;
        $this->runLoop = true;
        $this->quietTime = $quietTime;
        $this->processQueue = new \SplQueue();
        $this->processes = [];
        $this->syslog = $syslog;
        $this->daemonize = true;
        $this->producers = new \SplObjectStorage();

        // setup event dispatcher
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber($this);

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
            Processes\Event::START => 'onProcessStart',
            Processes\Event::EXIT => 'onProcessExit'
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
        $this->dispatcher->dispatch(DaemonEvent::START, new DaemonEvent($this));

        if ($this->daemonize) {
            try {
                $this->pid = Process::daemonize();
            } catch (\RuntimeException $e) {
                $this->log(LOG_ERR, $e->getMessage());
            }

            $this->log(LOG_INFO, "Became daemon with PID " . $this->pid);
            if ($this->pidFile) {
                if (!($fp = @\fopen($this->pidFile, 'w+'))) {
                    $this->log(LOG_ERR, "Failed to open/create PID file: " . $this->pidFile);
                    exit(1);
                }
                \fwrite($fp, $this->pid);
                \fclose($fp);
            }
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
     * Register task producer
     *
     * @param Tasks\Producer $producer
     *
     * @return $this
     */
    public function addProducer(Tasks\Producer $producer) {
        if ($producer instanceof EventSubscriberInterface) {
            $this->dispatcher->addSubscriber($producer);
        }
        $this->producers->attach($producer);
    }

    /**
     * Unregister task producer
     *
     * @param Tasks\Producer $producer
     *
     * @return $this
     */
    public function removeProducer(Tasks\Producer $producer) {
        if ($producer instanceof EventSubscriberInterface) {
            $this->dispatcher->removeSubscriber($producer);
        }
        $this->producers->detach($producer);
    }

    /**
     * Main work loop
     *
     * @return void
     */
    protected function loop() {
        while ($this->runLoop) {
            if (count($this->processes) < $this->maxProcesses) {
                $this->fillProcessQueue();
            }

            // look for queued work, execute it
            if ($this->processQueue->count() > 0) {
                while (count($this->processes) <= $this->maxProcesses && $this->processQueue->count() > 0) {
                    $process = $this->processQueue->dequeue();
                    $process->fork();
                }
            }

            // iterate through tasks dispatching to listeners
            foreach ($this->processes as $pid => $process) {
                $this->dispatcher->dispatch(DaemonEvent::LOOP_ITERATION, new DaemonEvent($this));

                try {
                    $process->reap();
                } catch (\RuntimeException $e) {
                    $this->log(LOG_ERR, "Failed to reap process: " . $e->getMessage());
                }
            }

            usleep($this->quietTime);
        }

        $this->dispatcher->dispatch(DaemonEvent::STOP, new DaemonEvent($this));
    }

    /**
     * Attempt to fill process queue from producers
     *
     * @return void
     */
    protected function fillProcessQueue() {
        foreach ($this->producers as $producer) {
            if ($task = $producer->produce()) {
                if (!is_array($task)) {
                    $task = [$task];
                }
                foreach ($task as $t) {
                    $this->processQueue->enqueue(new Processes\Process($t, $this->dispatcher));
                }
            }
        }
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
     * Get event dispatcher
     *
     * @return EventDispather
     */
    public function getDispatcher() {
        return $this->dispatcher;
    }

    /**
     * Record process
     *
     * @param Processes\Event Event object
     *
     * @return void
     */
    public function onProcessStart(Processes\Event $event) {
        $p = $event->getProcess();
        $this->dispatcher->dispatch(Tasks\Event::START, new Tasks\Event($p->getTask()));
        $this->log(LOG_NOTICE, "Spawned child with PID " . $p->getPid());
        $this->processes[$p->getPid()] = $p;
    }

    /**
     * Remove process
     *
     * @param Processes\Event Event object
     *
     * @return void
     */
    public function onProcessExit(Processes\Event $event) {
        $p = $event->getProcess();
        $this->dispatcher->dispatch(Tasks\Event::END, new Tasks\Event($p->getTask()));
        $this->log(LOG_INFO, "Child with PID " . $p->getPid() . " exited with status " . $p->getExitStatus() . ", runtime was " . sprintf("%.3f", $process->runtime()) . "ms");
        unset($this->processes[$p->getPid()]);
    }
}
