<?php
namespace SeanKndy\Daemon;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class Daemon implements EventSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

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

    /**
     * Constructor
     *
     * @param int $maxProcesses Maximum number of processes that can run at once
     * @param int $quietTime Length of time to pause between loop iterations
     * @param LoggerInterface $logger Logger for events
     *
     * @return $this
     */
    public function __construct($maxProcesses = 100, $quietTime = 1000000, LoggerInterface $logger = null) {
        $this->processQueue = new \SplQueue();
        $this->processes = [];
        $this->maxProcesses = $maxProcesses;

        $this->pid = 0;
        $this->pidFile = null;
        $this->runLoop = true;
        $this->daemonize = true;
        $this->quietTime = $quietTime;

        $this->logger = $logger == null ? new NullLogger : $logger;
        $this->producers = new \SplObjectStorage();

        // setup event dispatcher
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber($this);

        return $this;
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
     * Attempt to fill process queue from producers, distributing evenly
     * among producers
     *
     * @return void
     */
    protected function fillProcessQueue() {
        $maxFill = $this->maxProcesses - count($this->processes);

        for ($n = 0; $n < $maxFill; ) {
            $empty = true;
            foreach ($this->producers as $producer) {
                if (($task = $producer->produce()) instanceof Tasks\Task) {
                    $empty = false;

                    $this->processQueue->enqueue(new Processes\Process($task, $this->dispatcher));

                    if (++$n == $maxFill) {
                        break;
                    }
                }
            }
            if ($empty) {
                break;
            }
        }
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
                $this->logger->error($e->getMessage());
            }

            $this->logger->notice("Became daemon with PID " . $this->pid);
            if ($this->pidFile) {
                if (!($fp = @\fopen($this->pidFile, 'w+'))) {
                    $this->logger->error("Failed to open/create PID file: " . $this->pidFile);
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
            // fill up on tasks if we can
            $this->fillProcessQueue();

            // look for queued work, execute it
            if ($this->processQueue->count() > 0) {
                while (count($this->processes) <= $this->maxProcesses && $this->processQueue->count() > 0) {
                    $process = $this->processQueue->dequeue();
                    $process->fork();
                }
            }

            // iterate through processes dispatching to listeners
            foreach ($this->processes as $pid => $process) {
                $this->dispatcher->dispatch(Processes\Event::ITERATION, new Processes\Event($process));

                try {
                    $process->reap();
                } catch (\RuntimeException $e) {
                    $this->logger->error("Failed to reap process: " . $e->getMessage());
                }
            }

            $this->dispatcher->dispatch(DaemonEvent::LOOP_ITERATION, new DaemonEvent($this));
            usleep($this->quietTime);
        }

        $this->dispatcher->dispatch(DaemonEvent::STOP, new DaemonEvent($this));
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
        $this->logger->notice("Spawned child with PID " . $p->getPid());
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
        $this->logger->notice("Child with PID " . $p->getPid() . " exited with status " . $p->getExitStatus() . ", runtime was " . sprintf("%.3f", $p->runtime()) . "ms");
        unset($this->processes[$p->getPid()]);
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
     * Get LoggerInterface
     *
     * @return LoggerInterface
     */
    public function getLogger() {
        return $this->logger;
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
}
