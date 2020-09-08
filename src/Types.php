<?php

namespace OneCk;

class Types
{
    /**
     * @var Write
     */
    protected $write;

    /**
     * @var Read
     */
    protected $read;

    public function __construct($write, $read)
    {
        $this->write = $write;
        $this->read  = $read;
    }

    public static function encodeIpv4($ip)
    {
        return ip2long($ip);
    }

    public static function encodeIpv6($ip)
    {
        $ar = explode(':', $ip);
        if (count($ar) < 8 || strpos($ip, '::')) {
            $r = [];
            foreach ($ar as $v) {
                if (empty($v)) {
                    $r = array_merge($r, array_fill(0, 9 - count($ar), '0000'));
                    continue;
                }
                $r[] = strlen($v) < 4 ? str_repeat('0', 4 - strlen($v)) . $v : $v;
            }
            $ar = $r;
        }
        return hex2bin(implode($ar));
    }

    public static function encodeFixedString($str, $n)
    {
        return $str . str_repeat(chr(0), $n - strlen($str));
    }

    public static function encodeDate($date)
    {
        return ceil(strtotime($date) / 86400);
    }

    public static function encodeDatetime($datetime)
    {
        return strtotime($datetime);
    }

    protected function alias($type)
    {
        if (isset($this->base_types[$type])) {
            return $type;
        }

        if ($type === 'string') {
            return $type;
        }

        $arr = [
            'decimal32' => 'float32',
            'decimal64' => 'float64',
            'date'      => 'uint16',
            'datetime'  => 'uint32',
            'ipv4'      => 'uint32',
            'ipv6'      => 'fixedstring(16)',
            'enum8'     => 'int8',
            'enum16'    => 'int16'
        ];

        if (isset($arr[$type])) {
            return $arr[$type];
        }

        if (substr($type, 0, 8) === 'decimal(') {
            $arr = explode(',', substr($type, 8, -1));
            if ($arr[0] < 10) {
                return 'int32';
            } else if ($arr[0] < 19) {
                return 'int64';
            } else {
                return 'int128';
            }
        }
        if (substr($type, 0, 11) === 'datetime64(') {
            return 'uint64';
        }
        $this->arr_dp = [];
        while (substr($type, 0, 6) === 'array(') {
            $this->arr_dp[] = 'array';
            $type           = substr($type, 6, -1);
        }
        if (count($this->arr_dp) > 0) {
            if (substr($type, 0, 9) === 'nullable(') {
                $this->arr_val_is_null = true;
                $type                  = substr($type, 9, -1);
            }
            $this->arr_type = $type;
            return $this->alias($type);
        }
        return $type;
    }

    protected $arr_dp          = [];
    protected $arr_val_is_null = false;
    protected $arr_index_ar    = [];
    protected $arr_i           = 0;
    protected $arr_is_null     = [];
    protected $arr_type        = '';

    protected function getArrIndex(&$ret)
    {
        foreach ($ret as &$v) {
            $l = $v;
            $v = [];
            for ($i = 0; $i < $l; $i++) {
                $aa          = $this->sInfo('uint64');
                $v[]         = $aa - $this->arr_i;
                $this->arr_i = $aa;
            }
            $this->arr_index_ar[] = &$v;
        }
    }

    protected function getArrData($real_type, $type)
    {
        $j           = 0;
        $this->arr_i = 0;
        $ret         = [$this->sInfo('uint64')];
        $array_deep  = count($this->arr_dp) - 2;
        if ($array_deep > 0) {
            $this->getArrIndex($ret);
            while ($array_deep--) {
                $this->arr_i = 0;
                $l           = count($this->arr_index_ar);
                for ($i = $j; $i < $l + $j; $i++) {
                    $this->getArrIndex($this->arr_index_ar[$i]);
                }
                for ($i = $j; $i < $l + $j; $i++) {
                    unset($this->arr_index_ar[$i]);
                }
                $j += $l;
            }
        } else if ($array_deep === 0) {
            $this->getArrIndex($ret);
        } else {
            $ret                = [$ret];
            $this->arr_index_ar = [
                &$ret[0]
            ];
        }
        $this->arrIsNull();
        $j = 0;
        foreach ($this->arr_index_ar as &$val) {
            foreach ($val as &$vr) {
                $vr = array_fill(0, $vr, 0);
                foreach ($vr as &$v) {
                    $v = $this->readFormat($this->sInfo($real_type), $this->arr_type);
                    if (isset($this->arr_is_null[$j])) {
                        $v = null;
                    }
                    $j++;
                }
            }
        }
        $this->arr_is_null     = [];
        $this->arr_dp          = [];
        $this->arr_val_is_null = false;
        $this->arr_index_ar    = [];
        $this->arr_i           = 0;

        return $ret[0];
    }

    protected function arrIsNull()
    {
        $i = 0;
        if ($this->arr_val_is_null) {
            foreach ($this->arr_index_ar as $val) {
                foreach ($val as $_r) {
                    for ($k = 0; $k < $_r; $k++) {
                        $j = $this->read->number();
                        if ($j === 1) {
                            $this->arr_is_null[$i] = 1;
                        }
                        $i++;
                    }
                }
            }
        } else {
            $this->arr_is_null = [];
        }
    }

    /**
     * @param $data
     */
    protected function writeIsNull($data)
    {
        if ($this->arr_val_is_null) {
            foreach ($data as $i => $v) {
                if ($v === null) {
                    $this->arr_is_null[$i] = 1;
                } else {
                    $this->arr_is_null[$i] = 0;
                }
            }
            $this->write->number(...$this->arr_is_null);
            $this->arr_is_null = [];
        } else {
            $this->arr_is_null = [];
        }
    }


    protected function writeFormat($data, $type)
    {
        if (isset($this->base_types[$type])) {
            return $data;
        }

        if ($type === 'string') {
            return $data;
        }

        switch ($type) {
            case 'date':
                return self::encodeDate($data);
            case 'datetime':
                return self::encodeDatetime($data);
            case 'ipv4':
                return self::encodeIpv4($data);
            case 'ipv6':
                return self::encodeIpv6($data);
        }
        if (substr($type, 0, 8) === 'decimal(') {
            $arr = explode(',', substr($type, 8, -1));
            if (is_string($data)) {
                return str_replace('.', '', $data);
            } else {
                return $data * pow(10, $arr[1]);
            }
        }
        if (substr($type, 0, 11) === 'datetime64(') {
            $n = substr($type, 11, -1);
            return self::encodeDatetime64($data, $n);
        }
        return $data;
    }

    public static function encodeDatetime64($time, $n = 3)
    {
        $ar = explode('.', $time);
        $l  = isset($ar[1]) ? strlen($ar[1]) : 0;
        $n  = strtotime($ar[0]) . (isset($ar[1]) ? $ar[1] : '') . str_repeat('0', min(max($n - $l, 0), 9));
        return $n * 1;
    }

    protected function ipv6Unpack($data)
    {
        $s = bin2hex($data);
        $r = [];
        $a = '';
        for ($i = 0; $i < 32; $i++) {
            $a .= $s[$i];
            if ($i < 31 && $i % 4 === 3) {
                $r[] = ltrim($a, '0');
                $a   = '';
            }
        }
        $r[] = ltrim($a, '0');
        $r   = implode(':', $r);
        while (strpos($r, ':::') !== false) {
            $r = str_replace(':::', '::', $r);
        }
        return $r;
    }

    protected function readFormat($data, $type)
    {
        if (isset($this->base_types[$type])) {
            return $data;
        }

        if ($type === 'string') {
            return $data;
        }

        switch ($type) {
            case 'date':
                return date('Y-m-d', $data * 86400);
            case 'datetime':
                return date('Y-m-d H:i:s', $data);
            case 'ipv4':
                return long2ip($data);
            case 'ipv6':
                return $this->ipv6Unpack($data);
        }
        if (substr($type, 0, 8) === 'decimal(') {
            $arr = explode(',', substr($type, 8, -1));
            if (is_string($data)) {
                return substr($data, 0, -$arr[1]) . '.' . substr($data, -$arr[1]);
            } else {
                return $data / pow(10, $arr[1]);
            }
        }
        if (substr($type, 0, 11) === 'datetime64(') {
            return date('Y-m-d H:i:s', substr($data, 0, 10)) . '.' . substr($data, 10);
        }
        return $data;
    }

    private $base_types = [
        'int8'    => ['c', 1],
        'uint8'   => ['C', 1],
        'int16'   => ['s', 2],
        'uint16'  => ['S', 2],
        'int32'   => ['l', 4],
        'uint32'  => ['L', 4],
        'int64'   => ['q', 8],
        'uint64'  => ['Q', 8],
        'float32' => ['f', 4],
        'float64' => ['d', 8],
    ];

    /**
     * @param $type
     * @return mixed|string
     * @throws CkException
     */
    protected function sInfo($type)
    {
        $type = strtolower(trim($type));
        if (isset($this->base_types[$type])) {
            return unpack($this->base_types[$type][0], $this->read->fixed($this->base_types[$type][1]))[1];
        } else if ($type === 'string') {
            return $this->read->string();
        } else if (substr($type, 0, 12) === 'fixedstring(') {
            return $this->read->fixed(intval(substr($type, 12, -1)));
        } else if ($type === 'int128') {
            return $this->int128Unpack();
        } else if ($type === 'uuid') {
            return $this->uuidUnpack();
        } else {
            throw new CkException('not supported type :' . $type, CkException::CODE_NOT_SUPPORTED_TYPE);
        }
    }


    public function unpack($type, $row_count)
    {
        $real_type = $this->alias($type);
        if (isset($this->arr_dp[0])) {
            return $this->getArrData($real_type, $type);
        } else {
            return $this->readFormat($this->sInfo($real_type), $type);
        }
    }

    protected function setArrData($in_da, $type, $real_type)
    {
        $data   = [];
        $index  = [$in_da];
        $r      = [];
        $arr_dp = 0;
        while (true) {
            $del = [];
            $j   = 0;
            foreach ($index as $i => $val) {
                $j     += count($val);
                $r[]   = $j;
                $del[] = $i;
                if (isset($val[0]) && is_array($val[0])) {
                    foreach ($val as $v) {
                        $index[] = $v;
                    }
                } else {
                    $data = array_merge($data, $val);
                }
            }
            foreach ($del as $i) {
                unset($index[$i]);
            }
            if (empty($index)) {
                break;
            }
            $arr_dp++;
        }

        if ((count($this->arr_dp) - 1) !== $arr_dp) {
            throw new CkException('array deep err', CkException::CODE_ARR_ERR);
        }

        $this->write->addBuf(pack("{$this->base_types['uint64'][0]}*", ...$r));
        $this->writeIsNull($data);
        foreach ($data as $v) {
            $this->encode($this->writeFormat($v, $this->arr_type), $type, $real_type);
        }

        $this->arr_dp          = [];
        $this->arr_val_is_null = false;
        $this->arr_index_ar    = [];
        $this->arr_i           = 0;
        $this->arr_is_null     = [];

    }

    protected function encode($data, $type, $real_type)
    {
        if (isset($this->base_types[$real_type])) {
            $this->write->addBuf(pack("{$this->base_types[$real_type][0]}", $data));
        } else if ($real_type === 'string') {
            $this->write->string($data);
        } else if (substr($real_type, 0, 12) === 'fixedstring(') {
            $n = intval(substr($real_type, 12, -1));
            $this->write->addBuf(self::encodeFixedString($data, $n));
        } else if ($real_type === 'int128') {
            $this->write->addBuf($this->int128Pack($data));
        } else if ($real_type === 'uuid') {
            $this->write->addBuf(self::encodeUuid($data));
        } else {
            throw new CkException('unset type :' . $type, CkException::CODE_UNSET_TYPE);
        }
    }


    public function pack($data, $type)
    {
        $data      = $this->writeFormat($data, $type);
        $real_type = $this->alias($type);
        if (isset($this->arr_dp[0])) {
            $this->setArrData($data, $type, $real_type);
        } else {
            $this->encode($data, $type, $real_type);
        }
    }

    public static function encodeUuid($data)
    {
        $s = str_replace('-', '', $data);
        $r = '';
        for ($i = 14; $i >= 0; $i -= 2) {
            $r .= chr(hexdec($s[$i] . $s[$i + 1]));
        }
        for ($i = 30; $i >= 16; $i -= 2) {
            $r .= chr(hexdec($s[$i] . $s[$i + 1]));
        }
        return $r;
    }

    protected function uuidUnpack()
    {
        $s = bin2hex($this->read->fixed(8));
        $r = '';
        for ($i = 14; $i >= 0; $i -= 2) {
            $r .= $s[$i] . $s[$i + 1];
        }
        $r = substr($r, 0, 8) . '-' . substr($r, 8, 4) . '-' . substr($r, 12);

        $s  = bin2hex($this->read->fixed(8));
        $r1 = '';
        for ($i = 14; $i >= 0; $i -= 2) {
            $r1 .= $s[$i] . $s[$i + 1];
        }
        $r .= '-' . substr($r1, 0, 4) . '-' . substr($r1, 4);
        return $r;
    }

    /**
     * @param string $n
     * @return string
     */
    public function int128Pack($n)
    {
        if (!is_string($n)) {
            $n = "{$n}";
        }
        $is_n = false;
        if ($n[0] === '-') {
            $is_n = true;
        }
        $r = '';
        for ($i = 0; $i < 16; $i++) {
            $b = bcpow(2, 8);
            $c = bcmod($n, $b);
            $n = bcdiv($n, $b, 0);
            if ($is_n) {
                $v = ~abs(intval($c));
                if ($i === 0) {
                    $v = $v + 1;
                }
            } else {
                $v = intval($c);
            }
            $r .= chr($v);
        }
        return $r;
    }

    /**
     * @return string
     */
    protected function int128Unpack()
    {
        $str  = $this->read->fixed(16);
        $is_n = false;
        if (ord($str[15]) > 127) {
            $is_n = true;
        }
        $r = '0';
        for ($i = 0; $i < 16; $i++) {
            $n = ord($str[$i]);
            if ($is_n) {
                if ($i === 0) {
                    $n = $n - 1;
                }
                $n = ~$n & 255;
            }
            if ($n !== 0) {
                $r = bcadd(bcmul("{$n}", bcpow(2, 8 * $i)), $r);
            }
        }
        return $is_n ? '-' . $r : $r;
    }
}