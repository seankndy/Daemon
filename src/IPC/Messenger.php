<?php
namespace SeanKndy\Daemon\IPC;

interface Messenger {
    /**
     * Doing any init, called just before forking
     *
     * @return void
     */
    public function init() : void;

    /**
     * Send message
     *
     * @param string $message Message/data to send
     *
     * @return boolean
     */
    public function send(string $message) : bool;

    /**
     * Receive message
     *
     * @return string
     */
    public function receive() : string;

    /**
     * Has message pending
     *
     * @return boolean
     */
    public function hasMessage() : bool;
}
