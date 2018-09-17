<?php
/**
 * 对Where条件进行标准化
 * User: 蓝冰大侠
 * Date: 2018/4/17
 * Time: 14:24
 */
declare(strict_types=1);

namespace icePHP;

class MysqlWhere extends MysqlCondition
{
    /**
     * 构造方法
     * @param mixed $condition
     * @param string $operator
     */
    public function __construct($condition, $operator = 'AND')
    {
        parent::__construct($condition, $operator);
    }

    /**
     * 获取Where条件结果
     * @return array [sql,prepare,param]
     */
    public function result(): array
    {
        $sql = trim($this->sql);
        if (!$sql) {
            return ['', '', []];
        }

        //如果不是以WHERE开头,则,加上
        if (stripos($sql, 'WHERE') !== 0) {
            $sql = ' WHERE ' . $sql;
        }

        // 处理Prepare
        $prepare = trim($this->prepare);
        if (stripos($prepare, 'WHERE') !== 0) {
            $prepare = ' WHERE ' . $prepare;
        }

        // 返回三项
        return [$sql, $prepare, $this->param];
    }
}