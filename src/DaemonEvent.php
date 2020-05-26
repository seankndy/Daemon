<?php
namespace SeanKndy\Daemon;

class DaemonEvent extends \Symfony\Component\EventDispatcher\GenericEvent
{
     const START = 'daemon.start';
     const STOP = 'daemon.stop';
     const DAEMONIZED = 'daemon.daemonized';
     const LOOP_ITERATION = 'daemon.iteration';
}
