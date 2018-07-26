<?php
namespace SeanKndy\Tasks;

class Event extends Symfony\Component\EventDispatcher\Event {
     const START = 'task.start';
     const EXIT = 'task.exit';
     const ITERATION = 'task.iteration';

     /**
      * Task subject
      *
      * @var Task
      */
     protected $task;

     public function __construct(Task $task) {
         $this->task = $task;
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
