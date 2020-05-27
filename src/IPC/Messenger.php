<?php
namespace SeanKndy\Daemon\IPC;

interface Messenger
{
    /**
     * Send message
     *
     * @param string $message Message/data to send
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
