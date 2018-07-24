<?php
namespace SeanKndy\Daemon;

class IPC {
    public static $PARENT = 0;
    public static $CHILD = 1;

    protected $sockets = [];
    
    public function __construct() {
        if (@\socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets) === false) {
            throw new \RuntimeException("socket_create_pair() failed. Reason: " . socket_strerror(socket_last_error()));
        }
    }
    
    public function write($who, $data) {
        @\socket_close($this->sockets[$who == self::$CHILD ? self::$PARENT : self::$CHILD]);
        if (@\socket_write($this->sockets[$who], $data, strlen($data)) === false) {
            throw new \RuntimeException("socket_write() failed. Reason: ".socket_strerror(socket_last_error($this->sockets[$who])));
        }
    }
    
    public function read($who) {
        $data = '';
        while (($buf = @\socket_read($this->sockets[$who], 4096)) !== false && $buf != '') {
            $data .= $buf;
        }
        return $buf;
    }
    
    public function close($who) {
        @\socket_close($this->sockets[$who]);
    }
}
