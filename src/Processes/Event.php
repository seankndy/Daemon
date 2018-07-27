<?php
namespace SeanKndy\Daemon\Processes;

class Event extends \Symfony\Component\EventDispatcher\Event {
     const START = 'process.start';
     const EXIT = 'process.exit';
     const ITERATION = 'process.iteration';

     /**
      * Process subject
      *
      * @var Process
      */
     protected $process;

     public function __construct(Process $p) {
         $this->process = $p;
     }

     /**
      * Get Process subject
      *
      * @return Process
      */
     public function getProcess() {
         return $this->process;
     }
}
