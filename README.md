## Installing
```bash
$ composer require seankndy/daemon
```

## Usage overview
On each iteration of the main loop, _producers_ are called which should provide a _task_ to fork/run.

Producers can be callables or objects that implement `SeanKndy\Daemon\Tasks\Producer`.  Producers should return `null`
if there is no task to perform otherwise a `SeanKndy\Daemon\Tasks\Task` or `callable`.  `SeanKndy\Daemon\Tasks\Task`
has 3 methods:

`init()` - Called from parent process just before child is forked.
`run()` - Called from within forked process (child work)
`finish(int $status)` - Called from parent when process exits

So if you need to perform any initialization or teardown of as children are spawned/exiting, then
you'll want to make your own task classes implementing `SeanKndy\Daemon\Tasks\Task` rather than using


There are various events you can listen for (use `SeanKndy\Daemon\Daemon::addListener()`):

`SeanKndy\Daemon\DaemonEvent::START` - When daemon starts

`SeanKndy\Daemon\DaemonEvent::STOP` - When daemon stops

`SeanKndy\Daemon\DaemonEvent::DAEMONIZED` - When daemonized (backgrounded)

`SeanKndy\Daemon\DaemonEvent::LOOP_ITERATION` - Called at the end of each loop iteration

`SeanKndy\Daemon\Processes\Event::START` - New process started

`SeanKndy\Daemon\Processes\Event::EXIT` - New process exited

`SeanKndy\Daemon\Processes\Event::ITERATION` - Every main loop iteration this is fired for each running process


```php
<?php
use SeanKndy\Daemon\Daemon;
use SeanKndy\Daemon\Processes\Event as ProcessEvent;

$maxProcesses = 50;
$quietTime = 1000000;
$childTimeout = 30;

$daemon = new Daemon($maxProcesses, $quietTime, $childTimeout);

// $producer can be a callable or an object that implements SeanKndy\Daemon\Tasks\Producer
// it should produce work to do (which is also a callable or an object implementing SeanKndy\Daemon\Tasks\Task)
//
// if multiple producers are added, they will be round-robined
$number = 0;
$producer = function() use (&$number) {
    if ($number >= 10) return null;
    $number++;

    // this is the "task" or the code to run within the forked child
    return function() use ($number) {
        echo "hello from child $number, my pid is " . getmypid() . "\n";
        return 0; // child exit value
    };
};
$daemon->addProducer($producer);

// optional, signal catching
$daemon->addSignal(SIGTERM, function ($signo) {
    // SIGTERM caught, handle it here
});

// optional, example event listeners
$daemon->addListener(ProcessEvent::START, function ($event) {
    // process $event->getProcess() started
});
$daemon->addListener(ProcessEvent::EXIT, function ($event) {
    // process $event->getProcess() exited
});

$daemon->setDaemonize(false); // don't fork to background
$daemon->start();
```

## Inter-process Communication example

You can use `\SeanKndy\Daemon\IPC\Socket` to send message from child to parent using socket pairs.
Here is an example of doing that:

```php
<?php
use SeanKndy\Daemon\Tasks\Task;

class MyTask implements Task
{
    private $ipc;

    /**
     * Initialize task (main thread)
     *
     * @return void
     */
    public function init() : void
    {
        $this->ipc = new \SeanKndy\Daemon\IPC\Socket();
    }

    /**
     * Do the work (child thread)
     *
     * @return int
     */
     public function run() : int
     {
         // do something useful in child and generate $interestingData

         $this->ipc->send($interestingData);
         $this->ipc->close();
     }

     /**
      * Cleanup (main thread)
      *
      * @var int $status Exit status of child thread
      * @return void
      */
     public function finish(int $status) : void
     {
         if ($this->ipc->hasMessage()) {
             $interestingData = $this->ipc->receive();
             // $interestingData now in parent process
         }
         $this->ipc->close();
     }
}
