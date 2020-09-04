<?php

namespace OneCk;

class Read
{
    /**
     * @var resource
     */
    private $conn;

    private $buf = '';

    private $i = 0;

    private $len = 0;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    /**
     * 固定长度
     * @param int $n
     * @return string
     */
    public function fixed($n)
    {
        $s = str_repeat('1', $n);
        for ($i = 0; $i < $n; $i++) {
            $s[$i] = $this->getChar();
        }
        return $s;
    }

    private function getChar()
    {
        if ($this->i >= $this->len) {
            $this->get();
            if ($this->i >= $this->len) {
                throw new CkException('read fail', 10002);
            }
        }
        $r = $this->buf[$this->i];
        $this->i++;
        return $r;
    }

    private function get()
    {
        $buffer = fread($this->conn, 4096);
        if ($buffer === false) {
            throw new CkException('read from remote timeout', 10003);
        }
        $this->buf .= $buffer;
        $this->len = strlen($this->buf);
    }

    public function flush()
    {
        $this->buf = substr($this->buf, $this->i);
        $this->len = strlen($this->buf);
        $this->i   = 0;
    }

    public function clear()
    {
        $this->buf = '';
        $this->len = 0;
        $this->i   = 0;
    }

    /**
     * @return int
     * @throws CkException
     */
    public function number()
    {
        $r = 0;
        $b = 0;
        while (1) {
            $j = ord($this->getChar());
            $r = ($j << ($b * 7)) | $r;
            if ($j < 128) {
                return $r;
            }
            $b++;
        }
    }

    public function echo_str()
    {
        $s = $this->buf;
        echo "--- start ---\n";
        echo 'total len ' . strlen($s) . PHP_EOL;
        echo $s . PHP_EOL;
        for ($i = 0; $i < strlen($s); $i++) {
            echo $i . '=> ' . $s[$i] . '=>' . ord($s[$i]) . PHP_EOL;
        }
        echo PHP_EOL;
        echo "--- end ---\n";
    }

    /**
     * @return int
     * @throws CkException
     */
    public function int()
    {
        return unpack('l', $this->fixed(4))[1];
    }

    /**
     * @return string
     * @throws CkException
     */
    public function string()
    {
        $l = ord($this->getChar());
        $s = '';
        for ($i = 0; $i < $l; $i++) {
            $s .= $this->getChar();
        }
        return $s;
    }
}