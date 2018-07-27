<?php
namespace SeanKndy\Daemon;

class DeamonEvent extends \Symfony\Component\EventDispatcher\GenericEvent {
     const START = 'daemon.start';
     const STOP = 'daemon.stop';
     const LOOP_ITERATION = 'daemon.iteration';
}
