<?php
namespace SeanKndy\Daemon\Tasks;

class Event extends \Symfony\Component\EventDispatcher\Event {
     const START = 'task.start';
     const END = 'task.end';

     /**
      * Task subject
      *
      * @var Task
      */
     protected $task;

     public function __construct(Task $t) {
         $this->task = $t;
     }

     /**
      * Get Task subject
      *
      * @return Task
      */
     public function getTask() {
         return $this->task;
     }
}
