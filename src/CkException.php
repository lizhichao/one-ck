<?php

namespace OneCk;

class CkException extends \Exception
{
    const CODE_READ_FAIL          = 10001;
    const CODE_NOT_SUPPORTED_TYPE = 10002;
    const CODE_UNSET_TYPE         = 10003;
    const CODE_WRITE_FAIL         = 10004;
    const CODE_UNDO               = 10005;
    const CODE_UNDEFINED          = 10006;
    const CODE_INSERT_ERR         = 10007;
    const CODE_TODO_WRITE_START   = 10008;
    const CODE_RECEIVE_NULL       = 10009;

    const CODE_ARR_ERR = 10010;
}