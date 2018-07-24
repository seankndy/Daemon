<?php
namespace SeanKndy\Daemon;

class IPC {
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
        return $this;
    }

    /**
     * Open file descriptors (call from main thread)
     *
     * @return void
     */
    public function create() {
        if (@\socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets) === false) {
            throw new \RuntimeException("socket_create_pair() failed. Reason: " . socket_strerror(socket_last_error()));
        }
    }

    /**
     * Write data to appropriate socket
     *
     * @param string $data Data to write
     *
     * @return void
     */
    public function write($data) {
        if (!$this->sockets) {
            throw new \RuntimeException("Cannot call write() before calling create()!");
        }
        // close opposite FD
        @\socket_close($this->sockets[$this->isChild() ? self::PARENT : self::CHILD]);
        $who = $this->isChild() ? self::CHILD : self::PARENT;
        if (@\socket_write($this->sockets[$who], $data, strlen($data)) === false) {
            throw new \RuntimeException("socket_write() failed. Reason: ".socket_strerror(socket_last_error($this->sockets[$who])));
        }
    }

    /**
     * Read data from appropriate socket
     *
     * @return string
     */
    public function read() {
        if (!$this->sockets) {
            throw new \RuntimeException("Cannot call read() before calling create()!");
        }
        $who = $this->isChild() ? self::CHILD : self::PARENT;
        $data = $buf = '';
        while (($len = @\socket_recv($this->sockets[$who], $buf, 4096, MSG_DONTWAIT)) !== false && $len > 0) {
            $data .= $buf;
        }
        return $data;
    }

    /**
     * Close appropriate socket
     *
     * @return void
     */
    public function close() {
        $who = $this->isChild() ? self::CHILD : self::PARENT;
        @\socket_close($this->sockets[$who]);
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
