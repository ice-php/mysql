<?php
/**
 * 对MYSQL中的Group By及Order By进行标准化
 * User: 蓝冰大侠
 * Date: 2018/4/17
 * Time: 15:54
 */
declare(strict_types=1);

namespace icePHP;

abstract class MysqlSort
{
    /**
     * @var mixed 保存原始输入
     */
    private $origin;

    /**
     * @var string 标准化结果
     */
    private $result = '';

    /**
     * @var string 前缀:ORDER BY/GROUP BY
     */
    protected $prefix;

    /**
     * SMysqlSort constructor.
     * @param $sort string|array|null 原始输入
     * @throws \Exception
     */
    public function __construct($sort)
    {
        $this->origin = $sort;

        $this->process();
    }

    /**
     * 具体处理排序情况
     * @throws \Exception
     */
    private function process(): void
    {
        $sort = $this->origin;

        // 未要求
        if (!$sort) {
            return;
        }

        // 如果是字符串,分解排序依据和排序方向
        if (is_string($sort)) {
            //去除前缀
            if (stripos($sort, $this->prefix) === 0) {
                $sort = mid($sort, $this->prefix);
            }

            $sort = explode(',', $sort);
        }

        // orderby或groupby中的排序依据 必须是空或0或字符串或数组或对象
        if (!is_array($sort)) {
            throw new \Exception('Order must be string or array :' . $sort);
        }

        // 第二项是ASC/DESC
        if (isset($sort[0]) and isset($sort[1]) and in_array(strtoupper($sort[1]), ['ASC', 'DESC'])) {
            $sort = [$sort[0] . ' ' . $sort[1]];
        }

        // 数组多项,逐个分析
        $ret = [];
        foreach ($sort as $key => $value) {
            if (!is_string($value)) {
                throw new \Exception('Sort By must be string:' . $value . ' from :' . $this->origin);
            }
            $ret[] = $this->item($key, $value);
        }

        // 加上相应的前缀,可能是order by / group by
        $this->result = trim(implode(',', $ret));
    }

    /**
     * 处理一个数组项
     * @param $key int|string 键
     * @param $value string 值
     * @return string
     * @throws \Exception
     */
    private function item($key, string $value): string
    {
        // 整型,表示不用关注 数组的键了.
        if (is_int($key)) {
            list($key, $value) = $this->itemWithoutKey($value);
        }

        // 去多余的空格
        $key = trim($key);
        $value = trim($value);

        // 如果 字段和方向的顺序写反了,翻过来
        if (strtoupper($key) == 'ASC' or strtoupper($key) == 'DESC') {
            list($key, $value) = [$value, $key];
        }

        // 已经确定了字段和方向
        $field = $key;
        $dim = strtoupper($value);

        // 排序中的方向不被允许
        if ($dim != 'ASC' and $dim != 'DESC') {
            throw new \Exception('Order direction must be asc or desc:' . $dim);
        }

        // 构造SQL中排序语法: 空格分隔
        if ($this->isField($field)) {
            $field = $this->markField($field);
        }

        return $field . ' ' . $dim;
    }

    /**
     * 分解一个没有键的数组元素
     * @param $value string 字符串
     * @return array [sortBy,direction]
     * @throws \Exception
     */
    private function itemWithoutKey(string $value): array
    {
        $value = $this->formatSort($value);
        $arr = explode(' ', $value);

        // 每一项排序依据最多是二项,(列名+升降)
        if (count($arr) > 2) {
            throw new \Exception('Order syntax invalid:' . $arr);
        }

        // 只指明了一个值,默认升序
        if (count($arr) == 1) {
            $arr[1] = 'asc';
        }

        // 排序的字段和方向
        return $arr;
    }

    /**
     * 返回标准化结果,如果不需要排序,返回空字符串
     * @return string
     */
    public function result(): string
    {
        if ($this->result) {
            return $this->prefix . ' ' . $this->result;
        }
        return '';
    }

    /**
     * 格式化排序依据,将多个空格缩减为一个
     * @param string $str
     * @return string
     */
    private function formatSort(string $str): string
    {
        // 去除 外空格
        $str = trim($str);

        // 多个空格换成单个空格
        while (strpos($str, '  ')) {
            $str = str_replace('  ', ' ', $str);
        }

        return $str;
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
     * 加上 MYSQL的字段定界符
     * @param string $str
     * @return string
     */
    private function markField(string $str): string
    {
        return '`' . trim($str, '`') . '`';
    }
}