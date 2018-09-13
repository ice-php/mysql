<?php
declare(strict_types=1);

namespace icePHP;

class MysqlException extends \Exception
{
    //缺失数据库连接配置
    const MISS_CONFIG = 1;

    //连接数据库失败
    const CONNECT_FAIL = 2;

    //字段列表必须是字符串或数组
    const FIELD_LIST_FORMAT_ERROR = 3;

    //字段名必须是字符串
    const FIELD_NAME_MUST_STRING = 4;

    //字段别名必须是字符串
    const FIELD_ALIAS_MUST_STRING = 5;

    //连接操作符无法识别
    const JOIN_UNKNOWN = 6;

    //要连接的表名无法识别
    const JOIN_TABLE_UNKNOWN = 7;

    //要连接的表必须使用字符串或数组表示
    const JOIN_TABLE_TYPE_ERROR = 8;

    //一次只能连接一个表
    const JOIN_ONCE = 9;

    //关联关系错误
    const RELATION_ERROR=10;

    //分页参数必须是字符串或数组
    const LIMIT_ERROR=11;

    //表名不能为空
    const TABLE_NAME_NULL=12;

    //表名格式错误
    const TABLE_NAME_FORMAT=13;

    //表名中不能有特殊符号
    const TABLE_NAME_INVALID=14;

    //SQL语句格式错误
    const SQL_FORMAT=15;

    //查询命令无法识别或不支持
    const QUERY_COMMAND=16;

    //操纵命令无法识别或不支持
    const EXECUTE_COMMAND=17;

    //无法识别操纵命令中的表名
    const EXECUTE_TABLE_NAME=18;

    //条件值必须是字符串或数字
    const CONDITION_VALUE_TYPE=19;

    //in运算符的值必须是字符串或数组
    const IN_VALUE_ERROR=20;

    //between操作的值无法识别
    const BETWEEN_VALUE_ERROR=21;

    //between操作的值必须是字符串或数组
    const BETWEEN_TYPE_ERROR=22;

    //指定条件时必须是字符串或数组
    const CONDITION_TYPE_ERROR=23;

    //指定排序时必须是字符串或数组
    const ORDER_TYPE_ERROR=24;

    //排序字段必须是字符串
    const ORDER_FIELD_STRING=25;

    //指定排序的语法错误
    const ORDER_SYNTAX_ERROR=26;

    //排序方向只能使用ASC或DESC
    const ORDER_DIRECTION_ERROR=27;

    //更新时必须指定数据
    const MISS_DATA_IN_MODIFY=28;

    //更新时数据类型错误
    const DATA_TYPE_ERROR_IN_MODIFY=29;

    //更新时字段名必须是字符串
    const FIELD_NAME_IN_MODIFY=30;

    //更新时字段的值必须是字符串或数值
    const VALUE_IN_MODIFY=31;

    //更新时没有有效的数据
    const INVALID_MODIFY=32;
}