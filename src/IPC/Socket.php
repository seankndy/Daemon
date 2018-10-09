<?php
namespace SeanKndy\Daemon\IPC;

class Socket implements Messenger {
    const PARENT = 0;
    const CHILD = 1;

    /**
     * Parent process PID (main thread ID)
     * @var int
     */
    protected $ppid;

    /**
     * @var array
     */
    protected $sockets;


    /**
     * Constructor, always called from main process thread
     *
     * @return $this
     */
    public function __construct() {
        $this->sockets = [];
        $this->ppid = \getmypid();

        $factory = new \Socket\Raw\Factory();
        $this->sockets = $factory->createPair(AF_UNIX, SOCK_STREAM, 0);

        return $this;
    }

    /**
     * Messenger Implementation
     * Write data to appropriate socket
     *
     * @param string $message Data to write to socket
     *
     * @return boolean
     */
    public function send(string $message) : bool {
        // close opposite FD
        $this->sockets[$this->isChild() ? self::PARENT : self::CHILD]->close();

        $who = $this->isChild() ? self::CHILD : self::PARENT;
        return $this->sockets[$who]->write($message) ? true : false;
    }

    /**
     * Messenger Implementation
     * Read data from appropriate socket
     *
     * @return string
     */
    public function receive() : string {
        $who = $this->isChild() ? self::CHILD : self::PARENT;
        $data = '';
        while ($this->hasMessage() && $buf = $this->sockets[$who]->recv(4096, MSG_DONTWAIT)) {
            $data .= $buf;
        }
        return $data;
    }

    /**
     * Has message pending
     *
     * @return boolean
     */
    public function hasMessage() : bool {
        $who = $this->isChild() ? self::CHILD : self::PARENT;
        return $this->sockets[$who]->selectRead();
    }

    /**
     * Close appropriate socket
     *
     * @return void
     */
    public function close() {
        $who = $this->isChild() ? self::CHILD : self::PARENT;
        $this->sockets[$who]->close();
    }

    /**
     * Determine if PID matches parent
     *
     * @return boolean
     */
    private function isChild() {
        return (\getmypid() != $this->ppid);
    }
}
