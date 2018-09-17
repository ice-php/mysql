<?php
declare(strict_types=1);

namespace icePHP;

class MysqlException extends \Exception
{

    //更新时必须指定数据
    const MISS_DATA_IN_MODIFY=28;

    //更新时没有有效的数据
    const INVALID_MODIFY=32;
}