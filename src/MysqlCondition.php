<?php
declare(strict_types=1);

namespace icePHP;

/**
 * 专用于处理MYSQL  WHERE/Having 的 条件表达式
 * User: 蓝冰大侠
 * Date: 2018/4/17
 * Time: 11:04
 */
class MysqlCondition
{
    /**
     * @var mixed 原始条件备份
     */
    private $origin;

    /**
     * @var string 处理完成后的SQL语句
     */
    protected $sql = '';

    /**
     * @var string 处理完成后的Prepare语句,带占位符
     */
    protected $prepare = '';

    /**
     * @var array 处理完成后的Prepare参数数组
     */
    protected $param = [];

    //本层操作的运算符 AND/OR
    private $operator;

    /**
     * SMysqlCondition constructor.
     * @param $condition mixed 各种允许的条件输入, 数组|字符串|数值|空
     * @param $operator string 本层运算符
     */
    protected function __construct($condition, string $operator = 'AND')
    {
        //保存原始条件
        $this->origin = $condition;
        $this->operator = $operator;

        //字符串去前后空格
        if (is_string($condition)) {
            $condition = trim($condition);
        }

        // 空条件,即无条件
        if (empty($condition)) {
            $this->empty();
            return;
        }

        // 条件为一个数值,默认为ID=?
        if (is_numeric($condition)) {
            $this->numeric($condition);
            return;
        }

        // 如果条件是字符串,已经拼接完成的,不再变了.
        if (is_string($condition)) {
            $this->string($condition);
            return;
        }

        //如果是数组
        if (is_array($condition)) {
            $this->isArray($condition);
            return;
        }

        // 条件必须是空或字符串或数值或数组
        trigger_error('指定条件时必须是字符串或数组:' . json($condition), E_USER_ERROR);
    }

    /**
     * 获取条件标准化结果
     * @return array [sql,prepare,param]
     */
    public function result(): array
    {
        if (!$this->sql) {
            return ['', '', []];
        }
        return [$this->sql, $this->prepare, $this->param];
    }

    /**
     * 条件数组中的元素,没有指定键的情况下
     * @param $value mixed 值
     * @return array [SQL,Prepare,Param]
     */
    private function itemWithoutKey($value): array
    {

        // 如果是数组,按下一级条件拼接条件
        if (is_array($value)) {
            // 递归调用,生成子表达式
            list ($s, $subPrepared, $subParams) = (new self($value, $this->operator == 'AND' ? 'OR' : 'AND'))->result();
            return ['(' . $s . ')', '(' . $subPrepared . ')', $subParams];
        }

        // 必须是字符串了!!!
        if (!is_string($value)) {
            trigger_error('条件必须是数组或字符串:' . json($this->origin) . ':' . json($value),E_USER_ERROR);
        }

        return [$value, $value, []];
    }

    /**
     * 条件数组中的元素的键 有用的时候
     * @param $key string|int 键
     * @param $value mixed  值
     * @return array [SQL,Prepare,Param]
     */
    private function itemWithKey($key, $value): array
    {
        // 条件中的值,必须是数值或字符串
        if (!is_numeric($value) and !is_string($value) and !is_array($value)) {
            trigger_error('条件值必须是字符串或数字:' . json($this->origin) . ':' . json($value), E_USER_ERROR);
        }

        // 转义字符串的值
        if (is_string($value)) {
            $value = trim($value);
        }

        // 如果键是字段名,那么只能是等于条件或IN
        if ($this->isField($key)) {
            // 如果值 不是数组,那就是等于条件
            if (!is_array($value)) {
                $key = $this->markField($key);

                // 确定了三项的内容
                return [$key . "=" . $this->markValue($value), $key . '=?', $value];
            }

            // 值是数组,就只能是IN了
            $key = $key . ' in';
        }

        // 是否是其它操作运算符
        list ($op, $k) = $this->getOperator($key);

        // 不是其它运算符,三项也可确定了
        if (!$op) {
            return [$key . "=" . $this->markValue($value), $key . "=?", $value];
        }

        //键是一个字段
        $key = $this->markField($k);

        // 处理其它运算符的三项
        switch ($op) {
            case 'BETWEEN':
            case 'NOT BETWEEN':
                // 构造 Between的一对值
                $v = $this->markBetween($value);

                // 生成三项
                return [$key . ' ' . $op . ' ' . $this->sqlBetween($v), $key . ' ' . $op . ' ' . $this->prepareBetween(), $v];
            case 'IN':
            case 'NOT IN':
                // 如果 In/Not In 空数组
                if (empty($value)) {
                    if ($op == 'IN') {
                        // 固定为假
                        return ['FALSE', 'FALSE', []];
                    } else {
                        // 固定为真
                        return ['TRUE', 'TRUE', []];
                    }
                }

                // 构造In的值为数组
                $v = $this->markIn($value);

                // 计算三项
                return [$key . ' ' . $op . ' ' . $this->sqlIn($v), $key . ' ' . $op . ' ' . $this->prepareIn($v), $v];
            case 'IS NULL':
            case 'IS NOT NULL':
                return [$key . ' ' . $op . ' ', $key . ' ' . $op . ' ', []];
            default:
                return [$key . ' ' . $op . ' ' . $this->markValue($value), $key . ' ' . $op . ' ? ', $value];
        }
    }

    /**
     * 处理一个数组元素
     * @param $key string|int 键
     * @param $value mixed 值
     * @return array|null
     */
    private function item($key, $value): ?array
    {
        // 键不是整数,有用,需要分析
        if (!is_numeric($key)) {
            return $this->itemWithKey(trim($key), $value);
        }

        //键是整数,无用
        return $this->itemWithoutKey($value);
    }

    /**
     * 如果输入是数组,则要分别处理
     * @param array $condition
     */
    private function isArray(array $condition)
    {
        // 三项数组
        $sqls = $prepared = $params = [];

        // 看来是有多个条件,逐个处理吧
        foreach ($condition as $key => $value) {
            $ret = $this->item($key, $value);
            if ($ret) {
                list($itemSql, $itemPrepare, $itemParam) = $ret;
                $sqls[] = $itemSql;
                $prepared[] = $itemPrepare;

                if (is_array($itemParam)) {
                    $params = array_merge($params, $itemParam);
                } else {
                    $params[] = $itemParam;
                }
            }
        }

        // 三项数组
        $this->sql = implode(' ' . $this->operator . ' ', $sqls);
        $this->prepare = implode(' ' . $this->operator . ' ', $prepared);
        $this->param = $params;
    }

    /**
     * 当输入为字符串时, 直接使用
     * @param string $str
     */
    private function string(string $str): void
    {
        $this->sql = $str;
        $this->prepare = $str;
        $this->param = [];
    }

    /**
     * 当输入为数值时,认为是与ID字段相等条件
     * @param $num float|int
     */
    private function numeric($num): void
    {
        //调节为整数
        $num = intval(round($num));

        //与id字段匹配
        $this->sql = " id=$num";
        $this->prepare = " id=?";
        $this->param = [$num];
    }

    /**
     * 当输入为空时
     */
    private function empty(): void
    {
        $this->sql = '';
        $this->prepare = '';
        $this->param = [];
    }

    /**
     * 判别条件表达式中,键名中的操作符
     * @param string $key
     * @return array(操作符,剩余)
     */
    private function getOperator(string $key): array
    {
        $k = preg_replace('/\s+/', ' ', strtoupper(trim($key)));

        // 逐个检查是否是以下操作
        foreach (['IS NOT NULL', 'IS NULL', 'REGEXP', 'LIKE', '<=>', '<>', '!=', 'NOT IN', 'IN', 'NOT BETWEEN', 'BETWEEN', '>=', '<=', '=', '>', '<'] as $op) {
            $len = strlen($op) + 1;
            if (substr($k, -$len) == ' ' . $op) {
                return [$op, substr($key, 0, -$len)];
            }
        }

        return ['', $key];
    }

    /**
     * 识别Between条件的数据
     * @param array|string $value
     * @return array
     */
    private function markBetween($value): array
    {
        // 如果是数组,直接返回
        if (is_array($value)) {
            return $value;
        }

        // 除了数组,只能是字符串
        if (!is_string($value)) {
            trigger_error('between操作的值必须是字符串或数组:' . json($value), E_USER_ERROR);
        }

        // 尝试按逗号分解
        $arr = explode(',', $value);
        if (2 == count($arr)) {
            return $arr;
        }

        // 尝试按空格分解
        $arr = explode(' ', $value);
        if (2 == count($arr)) {
            return $arr;
        }

        // 尝试按 AND 分解
        $matched = preg_match('/(.+)\sAND\s(.+)/is', $value, $arr);
        if (!$matched) {
            trigger_error('between操作的值无法识别:' . $value, E_USER_ERROR);
        }

        // 整理识别结果
        return array_shift($arr);
    }

    /**
     * 构造 Between条件的SQL
     * @param array $value
     * @return string
     */
    private function sqlBetween(array $value): string
    {
        return $this->markValue($value[0]) . ' AND ' . $this->markValue($value[1]) . ' ';
    }

    /**
     * 判断 是否可能是字段名
     * @param string $str
     * @return int
     */
    private function isField(string $str): int
    {
        return preg_match('/^[a-zA-Z\x{4e00}-\x{9fa5}][\w\x{4e00}-\x{9fa5}]+$/ui', $str);
    }

    /**
     * 把值进行处理,并加上定界符
     * @param mixed $value
     * @return string
     */
    private function markValue($value): string
    {
        return "'" . $this->escape($value) . "'";
    }

    /**
     * 内容转义,防注入
     * @param mixed $str
     * @return string
     */
    private function escape($str): string
    {
        // 使用自行替换
        return str_replace(['\x00', '\n', '\r', '\\', '\'', '"', '\x1a'], ['\\x00', '\\n', '\\r', '\\\\', '\\\'', '\\"', '\\x1a	'], $str);
    }

    /**
     * 加上 MYSQL的字段定界符
     * @param string $str
     * @return string
     */
    private function markField(string $str): string
    {
        return '`' . trim($str, '`') . '`';
    }

    /**
     * 构造Between条件的Prepare
     * @return string
     */
    private function prepareBetween(): string
    {
        return ' ? AND ? ';
    }

    /**
     * 识别In条件的参数数组
     * @param string|array $value
     * @return array
     */
    private function markIn($value): array
    {
        // 已经是数组,直接返回
        if (is_array($value)) {
            return $value;
        }

        // 除了数组,只能是字符串
        if (!is_string($value)) {
            trigger_error('in运算符的值必须是字符串或数组:' . json($value), E_USER_ERROR);
        }

        // 尝试按逗号分解
        $arr = explode(',', $value);
        if (count($arr) > 1) {
            return $arr;
        }

        // 尝试按空格分解
        $arr = explode(' ', $value);
        if (count($arr) > 1) {
            return $arr;
        }

        // 看来只能是单值了
        return [$value];
    }

    /**
     * 构造 In条件的SQL
     * @param array $value
     * @return string
     */
    private function sqlIn(array $value): string
    {
        // 将数组中的值,逐个加上定界符
        foreach ($value as $k => $v) {
            $value[$k] = $this->markValue($v);
        }

        // 数组转为 逗号分隔列表
        return '(' . implode(',', $value) . ')';
    }

    /**
     * 构造In条件的Prepare
     * @param array $value
     * @return string
     */
    private function prepareIn(array $value): string
    {
        // 将数组转换为(?,....)的形式
        return '(' . implode(',', array_fill(0, count($value), '?')) . ')';
    }
}