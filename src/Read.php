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

    public function getChar($n = 1)
    {
        $buffer = fread($this->conn, $n);
        if ($buffer === false) {
            throw new CkException('read from fail', CkException::CODE_READ_FAIL);
        }
        if (strlen($buffer) < $n) {
            $buffer .= $this->getChar($n - strlen($buffer));
        }
        return $buffer;
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
        return unpack('l', $this->getChar(4))[1];
    }

    /**
     * @return string
     * @throws CkException
     */
    public function string()
    {
//        $this->echo_str();
        return $this->getChar(ord($this->getChar()));
    }
}