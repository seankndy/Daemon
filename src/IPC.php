<?php
namespace SeanKndy\Daemon;

class IPC {
    public static $PARENT = 0;
    public static $CHILD = 1;

    protected $sockets = [];
    
    public function __construct() {
        ;
    }
    
    public function write($who, $data) {
        if (!$this->sockets) {
            if (@\socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->sockets) === false) {
                throw new \RuntimeException("socket_create_pair() failed. Reason: " . socket_strerror(socket_last_error()));
            }
        }
        @\socket_close($this->sockets[$who == self::$CHILD ? self::$PARENT : self::$CHILD]);
        if (@\socket_write($this->sockets[$who], $data, strlen($data)) === false) {
            throw new \RuntimeException("socket_write() failed. Reason: ".socket_strerror(socket_last_error($this->sockets[$who])));
        }
    }
    
    public function read($who) {
        $data = $buf = '';
        while (($len = @\socket_recv($this->sockets[$who], $buf, 4096, MSG_DONTWAIT)) !== false && $len > 0) {
            $data .= $buf;
        }
        return $data;
    }
    
    public function close($who) {
        @\socket_close($this->sockets[$who]);
    }
}
