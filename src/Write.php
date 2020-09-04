<?php

namespace OneCk;

class Write
{
    /**
     * @var resource
     */
    private $conn;

    private $buf = '';

    public function __construct($conn)
    {
        $this->conn = $conn;
    }


    /**
     * @param int ...$nr
     * @return $this
     */
    public function number(...$nr)
    {
        $r = [];
        foreach ($nr as $n) {
            $b = 0;
            while ($n >= 128) {
                $r[] = $n | 128;
                $b++;
                $n = $n >> 7;
            }
            $r[] = $n;
        }
        if ($r) {
            $this->buf .= pack('C*', ...$r);
        }
        return $this;
    }

    /**
     * @param int $n
     * @return $this
     */
    public function int($n)
    {
        $this->buf .= pack('l', $n);
        return $this;
    }

    /**
     * @param int ...$str
     * @return $this
     */
    public function string(...$str)
    {
        foreach ($str as $s) {
            $this->number(strlen($s));
            $this->buf .= $s;
        }
        return $this;
    }


    /**
     * @param $str
     * @return $this
     */
    public function addBuf($str)
    {
        $this->buf .= $str;
        return $this;
    }


    public function flush()
    {
        if ($this->buf === '') {
            return true;
        }

        $len = fwrite($this->conn, $this->buf);
        if ($len !== strlen($this->buf)) {
            throw new CkException('write fail', 10001);
        }
//        echo __METHOD__ . PHP_EOL;
//        for ($i = 0; $i < strlen($this->buf); $i++) {
//            echo $i . '->' . ord($this->buf[$i]) . ' => ' . $this->buf[$i] . PHP_EOL;
//        }
//        echo '------ end ------' . PHP_EOL;
        $this->buf = '';
        return true;
    }

}