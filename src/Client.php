<?php

namespace OneCk;

class Client
{
    /**
     * @var resource
     */
    private $conn;

    /**
     * @var Write
     */
    private $write;

    /**
     * @var Read
     */
    private $read;

    /**
     * @var Types
     */
    private $types;

    const NAME          = 'PHP-ONE-CLIENT';
    const VERSION       = 54213;
    const VERSION_MAJOR = 1;
    const VERSION_MINOR = 1;

    const DBMS_MIN_V_TEMPORARY_TABLES         = 50264;
    const DBMS_MIN_V_TOTAL_ROWS_IN_PROGRESS   = 51554;
    const DBMS_MIN_V_BLOCK_INFO               = 51903;
    const DBMS_MIN_V_CLIENT_INFO              = 54032;
    const DBMS_MIN_V_SERVER_TIMEZONE          = 54058;
    const DBMS_MIN_V_QUOTA_KEY_IN_CLIENT_INFO = 54060;

    private $conf = [];


    public function __construct($dsn = 'tcp://127.0.0.1:9000', $username = 'default', $password = '', $database = 'default', $options = [])
    {
        $time_out   = isset($options['time_out']) ? $options['time_out'] : 3;
        $this->conn = stream_socket_client($dsn, $code, $msg, $time_out);
        if (!$this->conn) {
            throw new CkException($msg, $code);
        }
        stream_set_timeout($this->conn, $time_out);
        $this->write = new Write($this->conn);
        $this->read  = new Read($this->conn);
        $this->types = new Types($this->write, $this->read);
        $this->conf  = [$username, $password, $database];
        $this->hello(...$this->conf);
    }

    private function addClientInfo()
    {
        $this->write->string(self::NAME)->number(self::VERSION_MAJOR, self::VERSION_MINOR, self::VERSION);
    }

    private function hello($username, $password, $database)
    {
        $this->write->number(Protocol::CLIENT_HELLO);
        $this->addClientInfo();
        $this->write->string($database, $username, $password);
        $this->write->flush();
        return $this->receive();
    }

    /**
     * @return bool
     */
    public function ping()
    {
        $this->write->number(Protocol::CLIENT_PING);
        $this->write->flush();
        return $this->receive();
    }

    private $_server_info = [];
    private $_row_data    = [];
    private $_total_row   = 0;
    private $_is_null     = [];
    private $fields       = [];


    /**
     * @return array|bool
     */
    private function receive()
    {
        $this->_row_data  = [];
        $this->_total_row = 0;
        $this->_is_null   = [];
        $this->fields     = [];
        $_progress_info   = [];
        $_profile_info    = [];

        $code = null;
        do {
            if ($code === null) {
                $code = $this->read->number();
            }
            switch ($code) {
                case Protocol::SERVER_HELLO:
                    $this->setServerInfo();
                    return true;
                case Protocol::SERVER_EXCEPTION:
                    $this->readErr();
                    break;
                case Protocol::SERVER_DATA:
                    $n = $this->readData();
                    if ($n > 1) {
                        $code = $n;
                    }
                    continue 2;
                case Protocol::SERVER_PROGRESS:
                    $_progress_info = [
                        'rows'       => $this->read->number(),
                        'bytes'      => $this->read->number(),
                        'total_rows' => $this->gtV(self::DBMS_MIN_V_TOTAL_ROWS_IN_PROGRESS) ? $this->read->number() : 0,
                    ];
                    $this->read->flush();
                    break;
                case Protocol::SERVER_END_OF_STREAM:
                    $this->read->flush();
                    return $this->_row_data;
//                    return [
//                        'total_row'     => $this->_total_row,
//                        'data'          => $this->_row_data,
//                        'field'         => $this->fields,
//                        'progress_info' => $_progress_info,
//                        'profile_info'  => $_profile_info,
//                    ];
                case Protocol::SERVER_PROFILE_INFO:
                    $_profile_info = [
                        'rows'                         => $this->read->number(),
                        'blocks'                       => $this->read->number(),
                        'bytes'                        => $this->read->number(),
                        'applied_limit'                => $this->read->number(),
                        'rows_before_limit'            => $this->read->number(),
                        'calculated_rows_before_limit' => $this->read->number()
                    ];
                    $this->read->flush();
                    break;
                case Protocol::SERVER_TOTALS:
                case Protocol::SERVER_EXTREMES:
                    throw new CkException('Report to me this error ' . $code, CkException::CODE_UNDO);
                    break;
                case Protocol::SERVER_PONG:
                    $this->read->flush();
                    return true;
                default:
                    throw new CkException('undefined code ' . $code, CkException::CODE_UNDEFINED);
            }
            $code = null;
        } while (true);
    }

    private function gtV($v)
    {
        return $this->_server_info['version'] >= $v;
    }


    /**
     * @return array
     */
    public function getServerInfo()
    {
        return $this->_server_info;
    }


    private function isNull($t, $n)
    {
        $t = strtolower($t);
        if (substr($t, 0, 9) === 'nullable(') {
            for ($i = 0; $i < $n; $i++) {
                $j = $this->read->number();
                if ($j === 1) {
                    $this->_is_null[$i] = 1;
                }
            }
            return substr($t, 9, -1);
        } else {
            $this->_is_null = [];
            return $t;
        }
    }


    private function readData()
    {
        if (count($this->fields) === 0) {
            $this->readHeader();
        }
        list($code, $row_count) = $this->readHeader();
        if ($row_count === 0) {
            return $code;
        }
        foreach ($this->fields as $t) {
            $f = $this->read->string();
            $t = $this->read->string();
            $t = $this->isNull($t, $row_count);
            for ($i = 0; $i < $row_count; $i++) {
                $v = $this->types->unpack($t);

                $this->_row_data[$i + $this->_total_row][$f] = isset($this->_is_null[$i]) ? null : $v;
            }
        }
        $this->_total_row += $row_count;
        $this->read->flush();
        return 1;
    }

    private function readHeader()
    {
        $n = $this->read->number();
        if ($n > 1) {
            return [$n, 0];
        }
        $info = [
            'num1'         => $this->read->number(),
            'is_overflows' => $this->read->number(),
            'num2'         => $this->read->number(),
            'bucket_num'   => $this->read->int(),
            'num3'         => $this->read->number(),
            'col_count'    => $this->read->number(),
            'row_count'    => $this->read->number(),
        ];
        if (count($this->fields) === 0) {
            for ($i = 0; $i < $info['col_count']; $i++) {
                $this->fields[$this->read->string()] = $this->read->string();
            }
        }
        $this->read->flush();
        return [0, $info['row_count']];
    }

    private function setServerInfo()
    {
        $this->_server_info              = [
            'name'          => $this->read->string(),
            'major_version' => $this->read->number(),
            'minor_version' => $this->read->number(),
            'version'       => $this->read->number(),
        ];
        $this->_server_info['time_zone'] = $this->gtV(self::DBMS_MIN_V_SERVER_TIMEZONE) ? $this->read->string() : '';
        $this->read->clear();
    }


    private function sendQuery($sql)
    {
        $this->write->number(Protocol::CLIENT_QUERY, 0);

        if ($this->gtV(self::DBMS_MIN_V_CLIENT_INFO)) {

            // query kind
            $this->write->number(1)
                // name, id, ip
                ->string('', '', '[::ffff:127.0.0.1]:0')
                // iface type tcp, os ser, hostname
                ->number(1)->string('', '');

            $this->addClientInfo();

            if ($this->gtV(self::DBMS_MIN_V_QUOTA_KEY_IN_CLIENT_INFO)) {
                $this->write->string('');
            }

        }

        $this->write->number(0, Protocol::STAGES_COMPLETE, Protocol::COMPRESSION_DISABLE)->string($sql);

    }

    /**
     * @param string $sql
     */
    public function query($sql)
    {
        $this->sendQuery($sql);
        return $this->writeEnd();
    }

    /**
     * @param string $table
     * @param string[][] $data
     * @return array|bool
     * @throws CkException
     */
    public function insert($table, $data)
    {
        $this->writeStart($table, array_keys($data[0]));
        $this->writeBlock($data);
        return $this->writeEnd();
    }

    /**
     * @param string $table
     * @param string[] $fields
     * @throws CkException
     */
    public function writeStart($table, $fields)
    {
        $table = trim($table);
        $this->sendQuery('INSERT INTO ' . $table . ' (' . implode(',', $fields) . ') VALUES ');
        $this->writeEnd(false);
        while (true) {
            $code = $this->read->number();
            if ($code == Protocol::SERVER_DATA) {
                $this->readHeader();
                break;
            } else if ($code == Protocol::SERVER_PROGRESS) {
                continue;
            } else if ($code == Protocol::SERVER_EXCEPTION) {
                $this->readErr();
            } else {
                throw new CkException('insert err code:' . $code, CkException::CODE_INSERT_ERR);
            }
        }
    }

    /**
     * @param string[][] $data
     * @throws CkException
     */
    public function writeBlock($data)
    {
        if (count($this->fields) === 0) {
            throw new CkException('Please execute first writeStart', CkException::CODE_TODO_WRITE_START);
        }
        $this->writeBlockHead();

        // column count , row Count
        $row_count = count($data);
        $this->write->number(count($data[0]), $row_count);

        $new_data = [];
        foreach ($data as $row) {
            foreach ($row as $k => $v) {
                $new_data[$k][] = $v;
            }
        }

        foreach ($new_data as $field => $data) {
            $type = $this->fields[$field];
            $this->write->string($field, $type);
            $type = $this->writeIsNull($type, $data);
            $this->write->number(...$this->_is_null);
            foreach ($data as $i => $d) {
                $this->types->pack(
                    (isset($this->_is_null[$i]) && $this->_is_null[$i] === 1) ?
                        0 :
                        $d,
                    $type);
            }
            $this->write->flush();
        }
        $this->write->flush();

    }

    /**
     * @param $type
     * @param $field
     */
    private function writeIsNull($type, $data)
    {
        $t = strtolower($type);
        if (substr($t, 0, 9) === 'nullable(') {
            foreach ($data as $i => $v) {
                if ($v === null) {
                    $this->_is_null[$i] = 1;
                } else {
                    $this->_is_null[$i] = 0;
                }
            }
            return substr($t, 9, -1);
        } else {
            $this->_is_null = [];
            return $t;
        }
    }

    /**
     * @param false $get_ret
     * @return array|bool
     * @throws CkException
     */
    public function writeEnd($get_ret = true)
    {
        $this->writeBlockHead();
        $this->write->number(0);
        $this->write->number(0);
        $this->write->flush();
        if ($get_ret === true) {
            return $this->receive();
        }
    }

    private function writeBlockHead()
    {
        $this->write->number(Protocol::CLIENT_DATA);
        if ($this->gtV(self::DBMS_MIN_V_TEMPORARY_TABLES)) {
            $this->write->number(0);
        }
        if ($this->gtV(self::DBMS_MIN_V_BLOCK_INFO)) {
            $this->write->number(1, 0, 2);
            $this->write->int(-1);
            $this->write->number(0);
        }
    }

    private function readErr()
    {
        $c   = $this->read->int();
        $n   = $this->read->string();
        $msg = $this->read->string();
        $this->read->flush();
        throw new CkException(substr($msg, strlen($n) + 1), $c);
    }

}