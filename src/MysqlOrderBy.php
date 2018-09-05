<?php
/**
 * MYSQL中 ORDER BY 子句的标准化
 * User: 蓝冰大侠
 * Date: 2018/4/17
 * Time: 16:12
 */
declare(strict_types=1);

namespace icePHP;

class MysqlOrderBy extends MysqlSort
{
    protected $prefix='ORDER BY';

}