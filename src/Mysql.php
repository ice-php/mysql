<?php
declare(strict_types=1);

namespace icePHP;

/**
 * 本类只适用于MYSQL数据库
 * 负责数据库连接的类,被TableBase所使用
 * @author Ice
 */
final class Mysql
{

    /**
     * 禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 单例方法
     * @return Mysql
     */
    static public function instance(): Mysql
    {
        // 实例句柄
        static $handle;

        if (!$handle) {
            $handle = new self();
        }

        return $handle;
    }

    /**
     * 根据表的别名,连接数据库
     * @param string $alias 表的别名,即配置文件中的键名
     * @param string $mode write/read 为了读还是为了写,这将导致连接不同的数据库
     * @return \PDO
     */
    public function connect(string $alias, string $mode = 'write'): \PDO
    {
        // 参数检查
        $mode = strtolower($mode);
        if ($mode != 'read') {
            $mode = 'write';
        }

        // 读取数据库配置
        $all = self::getConfig();

        // 取相应的读/写服务器的连接信息
        if (isset($all[$alias])) {
            $connectInfo = $all[$alias][$mode];
        } else {
            $connectInfo = $all['_default'][$mode];
        }

        // 连接指定数据库
        return self::connectDatabase($connectInfo);
    }

    /**
     * 获取数据库配置信息
     * @return array ['_default'=>['read'=>[<连接信息>],'write'=>[<连接信息>]],'表别名'=>['read'=>...,'write'=>...,'table'=><原始表名>],...]
     */
    private function getConfig(): array
    {
        //静态化,只处理一次
        static $config;
        if ($config) return $config;

        // 读取整个数据连接的原始配置
        $origin = configDefault(null, 'database');
        if (!$origin or !is_array($origin)) {
            trigger_error("缺失数据库连接配置(database)", E_USER_ERROR);
        }

        //默认的读和写连接
        $defaultRead = $defaultWrite = null;

        //全部表配置
        $tables = [];

        //逐个查看原始配置,每一个是一个连接
        foreach ($origin as $key => $connect) {
            //配置文件中有一些不是连接用的配置项
            if (!is_int($key) and left($key, 1) == '_') {
                continue;
            }

            //访问模式
            $mode = $connect['mode'] ?? '读写';

            //如果本连接是默认连接
            if (isset($connect['default']) and $connect['default']) {
                //记录读和写的默认连接
                if (in_array($mode, ['读', '读写'])) {
                    $defaultRead = $connect['connect'];
                }
                if (in_array($mode, ['写', '读写'])) {
                    $defaultWrite = $connect['connect'];
                }
                continue;
            }

            //非默认连接,必须指定这个连接里有哪些表
            if (isset($config['tables']) and is_array($config['tables'])) :
                foreach ($connect['tables'] as $alias => $table) :
                    //如果未指定别名,则别名 与表名相同
                    if (is_numeric($alias)) {
                        $alias = $table;
                    }

                    //开始处理这个表的信息
                    if (!isset($tables[$alias])) {
                        $tables[$alias] = [];
                    }

                    //记录这个表的读连接和写连接
                    $tables[$alias]['table'] = $table;
                    if (in_array($mode, ['读', '读写'])) {
                        $tables[$alias]['read'] = $connect['connect'];
                    }
                    if (in_array($mode, ['写', '读写'])) {
                        $tables[$alias]['write'] = $connect['connect'];
                    }
                endforeach;
            endif;
        }

        //记录默认读和写连接
        $tables['_default'] = [
            'read' => $defaultRead,
            'write' => $defaultWrite
        ];

        //保存到静态变量中
        return $config = $tables;
    }

    /**
     * 连接指定数据库
     * @param array $connectInfo
     * @return \PDO
     */
    public static function connectDatabase(array $connectInfo): \PDO
    {
        // 连接句柄,会话内缓存
        static $connects = [];

        // 连接句柄的最后使用时间(用来控制过期)
        static $lastTime = [];

        // 数据库服务器的IP或域名
        $host = $connectInfo['host'];

        // 数据库服务器的端口号
        if (isset($connectInfo['port'])) {
            $port = $connectInfo['port'];
        } else {
            $port = '3306';
        }

        // 数据库用户的账号
        $user = $connectInfo['user'];

        // 数据库用户的密码
        $pass = $connectInfo['password'];

        // 数据库名称
        $database = $connectInfo['database'];

        // 数组的键
        $key = serialize([
            'host' => $host,
            'port' => $port,
            'name' => $database
        ]);

        // 取连接句柄的过期时间(秒)
        $timeout = configDefault(10, 'system', 'database_timeout');

        // 如果 过期,则删除,否则 会出错误
        if (isset($lastTime[$key]) and time() - $lastTime[$key] > $timeout) {
            unset($lastTime[$key]);
            unset($connects[$key]);
        }

        // 如果此连接已经存在,直接返回句柄
        if (isset($connects[$key])) {
            // 记录此句柄的最后使用时间
            $lastTime[$key] = time();
            return $connects[$key];
        }

        // 记录开始时间
        $start = timeLog();

        try {

            // 连接指定数据库
            $connect = new \PDO("mysql:host=$host;dbname=$database;port=$port", $user, $pass, [
                // 使用缓存
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,

                // 大小写有区别
                \PDO::ATTR_CASE => \PDO::CASE_NATURAL,

                // 有错抛出异常,而不是返回错误码
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,

                // 数值不要自动 转换成字符串
                \PDO::ATTR_STRINGIFY_FETCHES => false,

                // 自动提交,必须自动提交,否则 就需要显式Commit,否则 必然会丢失数据
                \PDO::ATTR_AUTOCOMMIT => true,

                // 超时,30秒
                \PDO::ATTR_TIMEOUT => $timeout
            ]);
        }catch (\PDOException $e){
            trigger_error('数据库连接失败:'.$database.'@'.$host.':'.$port,E_USER_ERROR);
        }

        // 设置一些必要的连接属性
        // 禁用preparedStatements的仿真效果
        $connect->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        $connect->exec("SET NAMES 'utf8mb4'");

        // 写调试信息
        Debug::setSql("Connect", '', timeLog($start), '', "$database at $host:$port");

        // 记录此句柄的最后使用时间
        $lastTime[$key] = time();

        // 保存连接句柄
        $connects[$key] = $connect;

        return $connect;
    }

    /**
     * 对某个表进行锁定(默认写锁)
     * @param string $tableName 表别名
     * @param string $level 锁级别,read/write
     */
    public function lock(string $tableName, string $level): void
    {
        // 连接数据库
        $connect = $this->connect($tableName);

        // 取消自动提交
        $connect->setAttribute(\PDO::ATTR_AUTOCOMMIT, false);

        // 锁表
        $connect->exec('lock tables ' . $tableName . ' ' . $level);
    }

    /**
     * 解除表锁定
     * @param string $tableName 表别名
     */
    public function unlock(string $tableName): void
    {
        // 连接数据库
        $connect = $this->connect($tableName);

        // 解锁表
        $connect->exec('unlock tables ');

        // 重新设置为自动提交
        $connect->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
    }

    /**
     * 判断 是否可能是字段名
     * @param string $str
     * @return int
     */
    public function isField(string $str): int
    {
        return preg_match('/^[a-zA-Z\x{4e00}-\x{9fa5}][\w\x{4e00}-\x{9fa5}]+$/ui', $str);
    }

    /**
     * 加上 MYSQL的字段定界符
     * @param string $str
     * @return string
     */
    public function markField(string $str): string
    {
        return '`' . trim($str, '`') . '`';
    }

    /**
     * 把值进行处理,并加上定界符
     * @param mixed $value
     * @return string
     */
    public static function markValue($value): string
    {
        return "'" . self::escape($value) . "'";
    }

    /**
     * 将数组中的每一个值,加上定界符
     * @param array $values
     * @return array
     */
    public function markValueArray(array $values): array
    {
        // 对数组中的每一项,逐个加定界符
        foreach ($values as $k => $v) {
            $values[$k] = self::markValue($v);
        }
        return $values;
    }

    /**
     * 对字段列表进行标准化
     * @param $fields mixed 字段列表
     * @return array 字段=>别名 数组
     */
    private function formatFields($fields): array
    {
        // 按逗号分解
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }

        // 列名信息必须是字符串或对象或数组或0或空
        if (!is_array($fields)) {
            trigger_error('字段列表必须是字符串或数组:' . json($fields), E_USER_ERROR);
        }

        $ret = [];

        // 每一个字段进行检查
        foreach ($fields as $key => $value) {
            // 列名必须是字符串
            if (!is_string($value) or is_numeric($value)) {
                trigger_error('字段名必须是字符串:' . json($value), E_USER_ERROR);
            }

            // 如果是整数,表明,未指定键名,只有键 值
            if (is_int($key)) {
                // 是字段名或表达式
                if ($this->isField($value)) {
                    $ret[] = $this->markField($value);
                } else {
                    $ret[] = $value;
                }
                continue;
            }

            // 非整数的数值,这不科学
            if (is_numeric($key)) {
                trigger_error('字段别名必须是字符串:' . json($key),E_USER_ERROR);
            }

            // 键是字段名
            if ($this->isField($key)) {
                $ret[] = $this->markField($key) . ' AS `' . trim($value) . '`';
                continue;
            }

            // 加上别名
            $ret[] = $key . ' AS `' . trim($value) . '`';
        }

        return $ret;
    }

    /**
     * 标准化字段列表,可以是各种输入格式
     * @param mixed $fields
     *            null/''/0 所有字段
     *            string 一个字段名,或者是用逗号分隔的字段列表
     *            object/array
     *
     *            =><别名>
     * @return string
     */
    public function createFields($fields = null): string
    {
        // 如果未指定字段,则,全部字段
        if (!$fields) {
            return '*';
        }

        $ret = $this->formatFields($fields);

        // 返回逗号分隔的列表
        return implode(',', $ret);
    }

    /**
     * 创建JOIN,ON语句
     * @param  $operations array JOIN的方向
     * @param $joins array 开发人员设置的join信息
     * @param $ons array 开发人员设置的 on 信息
     * @return string 拼接后的SQL
     */
    public function createJoins(array $operations, array $joins, array $ons): string
    {
        $ret = [];
        for ($i = 0; $i < count($joins); $i++) {
            $ret[] = $this->createJoin($operations[$i], $joins[$i]) . $this->createOn($ons[$i]);
        }
        return implode(' ', $ret);
    }

    /**
     * 构造一条JOIN语句,不带ON
     * @param $operation string JOIN的方向
     * @param $join mixed 原始JOIN,可能是字符串或一个键值对
     * @return string SQL语句
     */
    private function createJoin(string $operation, $join): string
    {
        $operation = strtolower($operation);
        if ($operation == 'left') {
            $operation = 'LEFT JOIN ';
        } elseif ($operation == 'right') {
            $operation = 'RIGHT JOIN ';
        } elseif ($operation == 'inner') {
            $operation = 'INNER JOIN ';
        } elseif ($operation == 'outer') {
            $operation = 'OUTER JOIN';
        } else {
            trigger_error('连接操作符无法识别:' . $operation,E_USER_ERROR);
        }

        //字符串
        if (is_string($join)) {
            //检查其中是否有别名要求
            $matches = null;
            if (!preg_match('/.+(\sas\s).+/i', $join, $matches)) {
                return $operation . $this->createTableName($join);
            }

            //分解原名与别名
            $parts = explode($matches[1], $join);
            if (count($parts) != 2) {
                trigger_error('要连接的表名无法识别:' . $join, E_USER_ERROR);
            }

            //返回带别名的JOIN语句
            return $operation . $this->createTableName($parts[0]) . ' AS ' . $this->createTableName($parts[1]);
        }

        //必须是字符串或数组
        if (!is_array($join)) {
           trigger_error('要连接的表必须使用字符串或数组表示:' . json($join),E_USER_ERROR);
        }

        //数组必须只有一个键值对
        if (count($join) > 1) {
            trigger_error('一次只能连接一个表:' . json($join), E_USER_ERROR);
        }

        $k = array_keys($join)[0];
        $v = array_values($join)[0];

        //只有值,无键
        if (is_int($k)) {
            return $operation . $this->createTableName($v);
        }

        //键值表示别名
        return $operation . $this->createTableName($k) . ' AS ' . $this->createTableName($v);
    }

    /**
     * 构造一条ON语句
     * @param $on mixed
     * @return string
     */
    private function createOn($on): string
    {
        return ' ON ' . implode(' = ', $this->createRelation($on));
    }

    /**
     * 处理关联关系
     * @param mixed $relation
     *            可以是:
     *            *** '本表字段'
     *            *** '本表字段=关联表字段'
     *            ***    array('本表字段'=>'关联表字段')
     *            ***    array('本表字段','关联表字段')
     * @return array(<本表字段>,<关联表字段>)
     */
    private function createRelation($relation): array
    {
        // 处理数据形式
        if (is_array($relation)) {
            foreach ($relation as $k => $v) {
                if (is_numeric($k)) {
                    return $v;
                }
                return [$k, $v];
            }
        }

        // 字符串形式
        if (!is_string($relation)) {
            trigger_error('关联关系错误:' . json($relation),E_USER_ERROR);
        }

        // 分解关联键
        $matches = explode('=', $relation);

        // 如果只指明了一个字段,默认后一个字段为_id/id(表的主键)
        if (count($matches) == 1) {
            return [$relation, 'id'];
        }

        // 返回两个关联键
        return $matches;
    }

    /**
     * 生成WHERE子句
     * @param mixed $where 参考createCondition
     * @return array
     */
    public function createWhere($where = false): array
    {
        $result = (new MysqlWhere($where))->result();
        return $result;
    }

    /**
     * 生成HAVING子句
     * @param mixed $having 参考createCondition
     * @return array
     */
    public function createHaving($having): array
    {
        return (new MysqlHaving($having))->result();
    }

    /**
     * 生成 GROUP BY 子句
     * @param mixed $groupBy 参考createSort
     * @return string
     */
    public function createGroupBy($groupBy): string
    {
        return (new MysqlGroupBy($groupBy))->result();
    }

    /**
     * 生成 ORDER BY 子句
     * @param mixed $orderBy 参考createSort
     * @return string
     */
    public function createOrderBy($orderBy = null): string
    {
        return (new MysqlOrderBy($orderBy))->result();
    }

    /**
     * 生成分页子句
     * @param mixed $limit
     *            string 空格或逗号或冒号分隔的开始与行数
     *            int 只限制行数
     *            array
     *            [<开始>,<行数>]
     *            [<行数>]
     * @return int|array(偏移,行数)
     */
    public function createLimit($limit = null)
    {
        // 未指定
        if (!$limit) {
            return [];
        }

        // 如果是字符串,分解吧
        if (is_string($limit)) {
            // 去首尾空格
            $limit = trim($limit);

            // 尝试以空格区分
            if (strpos($limit, ' ')) {
                $limit = explode(' ', $limit);
                return [intval($limit[0]), intval($limit[1])];
            }

            // 尝试以逗号区分
            if (strpos($limit, ',')) {
                $limit = explode(',', $limit);
                return [intval($limit[0]), intval($limit[1])];
            }

            // 尝试以:区分
            if (strpos($limit, ':')) {
                $limit = explode(':', $limit);
                return [intval($limit[0]), intval($limit[1])];
            }

            // 没找到间隔符,只能假设只指定了长度
            return [0, intval($limit)];
        }

        // 如果是整数,好办,只指定了长度
        if (is_int($limit)) {
            return [0, intval($limit)];
        }

        // 分页参数 必须是空或0或字符串或数组
        if (!is_array($limit)) {
            trigger_error('分页参数必须是字符串或数组:' . json($limit), E_USER_ERROR);
        }

        return [intval($limit[0]), intval($limit[1])];
    }

    /**
     * 尚未使用
     * @deprecated
     * @param $prepare
     * @param array $bind
     * @return array
     */
    public function createQuery($prepare, $bind = [])
    {
        if (!$bind) {
            return [$prepare, $prepare, []];
        }
        return [];
    }

    /**
     * 检查表名是否合法,并规范化
     * @param $tableName string
     * @return string 表名
     */
    public function createTableName(string $tableName): string
    {
        // 去空格,去定界符
        $tableName = trim($tableName, "\t\n\r\0\x0B `");

        // 不能为空
        if (!$tableName) {
            trigger_error('表名不能为空', E_USER_ERROR);
        }

        // 正则检查
        if (preg_match('/[\/\\\$\@\'"\[\]\(\)]/i', $tableName)) {
            trigger_error('表名格式错误:' . $tableName, E_USER_ERROR);
        }

        // 其实,不用查这个
        if (strpos($tableName, ',')) {
            trigger_error('表名中不能有特殊符号:' . $tableName,E_USER_ERROR);
        }

        // 返回纯净的表名
        return $tableName;
    }

    /**
     * 获取查询语句中的涉及表名
     * @param string $sql
     * @return array 表名列表
     */
    public function getNameFromQuery(string $sql): array
    {
        $matches = null;

        // 获取命令字
        if (!preg_match('/^\s*(\w+)\b/i', $sql, $matches)) {
            trigger_error('SQL语句格式错误:' . $sql, E_USER_ERROR);
        }

        // 取出语句的动词
        $op = strtolower($matches[0]);

        // 查询语句必须是指定开头,否则不承认
        if (!in_array($op, ['select', 'show', 'desc', 'repair', 'optimize', 'call'])) {
            trigger_error('查询命令无法识别或不支持:' . $matches[0], E_USER_ERROR);
        }

        // 匹配 表名部分 ,包括 From之后和Join之后
        if (!preg_match_all('/from\s+([\w|`]+)(\s*,\s*([\w|`]+))*|join\s+([\w|`]+)/i', $sql, $matches)) {
            return [];
        }

        // 收集匹配的表名
        $names = [];
        foreach ([1, 3, 4] as $i) {
            foreach ($matches[$i] as $name) {
                if ($name) {
                    array_push($names, trim($name, '`'));
                }
            }
        }
        return $names;
    }

    /**
     * 获取执行语句中的涉及表名
     * @param string $sql 语句
     * @return array 表名列表
     */
    public function getNameFromExecute(string $sql): array
    {
        $matches = null;

        // 获取命令字
        if (!preg_match('/^(\w+)\b/i', $sql, $matches)) {
            trigger_error('SQL语句格式错误:' . $sql, E_USER_ERROR);
        }

        // 只支持Insert,Update,Delete,Replace语句,其它不支持
        $command = strtolower($matches[0]);
        if (!in_array($command, ['insert', 'update', 'delete', 'replace'])) {
            trigger_error('执行命令无法识别或不支持:' . $command, E_USER_ERROR);
        }

        // 匹配表名部分,包括 insert [into] $tbl , replace [into] $tbl, update $tbl, delete from $tbl
        if (!preg_match_all('/insert\s+(into\s+)?([\w|`]+)|replace\s+(into\s+)?([\w|`]+)|delete\s+from\s+([\w|`]+)|update\s+([\w|`]+)/i', $sql, $matches)) {
            trigger_error('无法识别执行命令中的表名:' . $sql,E_USER_ERROR);
        }

        // 收集匹配的表名
        $names = [];

        // 匹配项中的第2,4,5,6项
        foreach ([2, 4, 5, 6] as $i) {
            // 每一项都是数组
            foreach ($matches[$i] as $name) {
                // 如果有匹配,去定界符,加入到表名列表中
                if ($name) {
                    array_push($names, trim($name, '`'));
                }
            }
        }
        return $names;
    }

    /**
     * 内容转义,防注入
     * @param mixed $str
     * @return string
     */
    static public function escape($str): string
    {
        // 使用自行替换
        return str_replace(['\x00', '\n', '\r', '\\', '\'', '"', '\x1a'], ['\\x00', '\\n', '\\r', '\\\\', '\\\'', '\\"', '\\x1a	'], $str);
    }

    /**
     * 构造 查看记录是否存在的SQL语句
     * @param string $sub 子查询
     * @return string
     */
    public function createExist(string $sub): string
    {
        return "SELECT " . "IF ( EXISTS ( $sub ) , 1 , 0 ) as cnt FROM DUAL ";
    }

    /**
     * 构造 查询语句,以下参数不解释了
     * @param string $tableName
     * @param string $fields
     * @param string $where
     * @param string $orderBy
     * @param string $groupBy
     * @param string $having
     * @param array|0 $limit
     * @return string
     */
    public function createSelect(string $tableName, string $fields, string $where, string $orderBy, string $groupBy, string $having, $limit = null): string
    {
        // 构造 SELECT语句
        $sql = 'SELECT ' . $fields . " FROM " . $tableName . ' ' . $where . ' ' . $groupBy . ' ' . $having . ' ' . $orderBy;

        // 附加分页参数
        if ($limit) {
            $sql .= " LIMIT {$limit[0]},{$limit[1]}";
        }

        return $sql;
    }

    /**
     * 构造 去重 查询语句,以下参数不解释了
     * @param string $tableName 表名
     * @param string $fields 字段
     * @param string $where 查询 条件
     * @param string $orderBy 排序
     * @param string $groupBy 分组
     * @param string $having 分组过滤
     * @param array $limit 分页
     * @return string
     */
    public function createDistinct(string $tableName, string $fields, string $where, string $orderBy, string $groupBy, string $having, array $limit = null): string
    {
        // 构造 SQL语句
        $sql = 'SELECT DISTINCT ' . $fields . " FROM " . $tableName . ' ' . $where . ' ' . $groupBy . ' ' . $having . ' ' . $orderBy;

        // 附加分页
        if ($limit) {
            $sql .= " LIMIT {$limit[0]},{$limit[1]}";
        }
        return $sql;
    }

    /**
     * 根据表名,字段名数组,值数组构造 Insert语句
     * @param string $tableName 表名
     * @param array $fields 字段名列表
     * @param array $values 值列表
     * @return string
     */
    public function createInsert(string $tableName, array $fields, array $values): string
    {
        // 构造插入语句
        return "INSERT " . "INTO " . $tableName . '(' . implode(',', $fields) . ') VALUES(' . implode(',', $values) . ')';
    }

    /**
     * 根据表名,字段名数组,行数组,构造 多行Insert语句
     * @param $tableName string 表名
     * @param array $fields 字段名数组
     * @param array $rows 多行数据
     * @return string SQL语句
     */
    public function createInserts(string $tableName, array $fields, array $rows): string
    {
        $sql = "INSERT " . "INTO {$tableName} (" . implode(',', $fields) . ') VALUES ';
        foreach ($rows as $row) {
            $sql .= '(' . implode(',', $row) . '),';
        }
        return trim($sql, ',');
    }

    /**
     * 根据表名,字段名列表,值列表,构造 Insert Ignore语句
     * @param string $tableName 表名
     * @param array $fields 字段名列表
     * @param array $values 值列表
     * @return string
     */
    public function createInsertIgnore(string $tableName, array $fields, array $values): string
    {
        // 构造插入语句,如果已经存在(约束),则忽略
        return "INSERT " . "IGNORE INTO " . $tableName . '(' . implode(',', $fields) . ') VALUES(' . implode(',', $values) . ')';
    }

    /**
     * 根据表名,字段名列表,值列表构造Replace语句
     * @param string $tableName 表名
     * @param array $fields 字段名列表
     * @param array $values 值列表
     * @return string
     */
    public function createReplace(string $tableName, array $fields, array $values): string
    {
        // 构造插入语句,如果已经存在(约束),则替换
        return "REPLACE " . "INTO " . $tableName . '(' . implode(',', $fields) . ') VALUES(' . implode(',', $values) . ')';
    }

    /**
     * 构造 删除语句
     * @param string $tableName 表名
     * @param string $where 条件
     * @return string
     */
    public function createDelete(string $tableName, string $where): string
    {
        // 构造 删除语句
        $sql = "DELETE " . "FROM " . $tableName . ' ' . $where;
        return $sql;
    }

    /**
     * 根据表名,SET列表,条件,构造Update语句
     * @param $tableName string 表名
     * @param $set array
     * @param $where string 条件
     * @return string
     */
    public function createUpdate(string $tableName, array $set, string $where): string
    {
        // 构造更新语句
        return "UPDATE " . $tableName . " SET " . implode(',', $set) . ' ' . $where;
    }

    /**
     * 构造 描述 语句
     * @param string $tableName 表名
     * @return string
     */
    public function createDesc(string $tableName): string
    {
        // 构造 表描述语句
        return 'SELECT ' . '* FROM Information_schema.columns WHERE table_name =' . self::markValue($tableName) . ' AND table_schema=DATABASE()';
    }

    /**
     * 构造获取索引信息的语句
     * @param string $tableName 表名
     * @return string 查询语句
     */
    public function createIndex(string $tableName): string
    {
        return 'SHOW ' . 'INDEX FROM ' . self::createTableName($tableName);
    }

    /**
     * 构造 显示所有表的语句
     * @return string
     */
    public static function createShowTables(): string
    {
        // 显示所有的表
        return 'SHOW FULL TABLES WHERE Table_type="BASE TABLE"';
    }

    /**
     * 构造 显示指定数据库所有表信息的语句
     * @param string $database 数据库名称
     * @return string
     */
    public function createDatabaseInfo(string $database): string
    {
        return "Select" . " table_name ,TABLE_COMMENT  from INFORMATION_SCHEMA.TABLES Where table_schema = '$database' ";
    }

    /**
     * 构造  显示指定 数据库所有表详细信息的语句
     * @param $database string 数据库名称
     * @return string
     */
    public function createTablesStatus(string $database): string
    {
        return "SHOW TABLE STATUS FROM `" . $database . "`";
    }

    /**
     * 构造 显示 表结构的语句
     * @param string $tableName 表名
     * @return string 语句
     */
    public function getCreate(string $tableName): string
    {
        // 显示表的构造语句
        return "SHOW CREATE TABLE " . $tableName;
    }

    /**
     * 构造Increase/Decrease的SQL
     * @param string $field 字段名
     * @param string $op 操作符 +/-
     * @param float|string $diff 增量  生成占位符时会使用?
     * @return string
     */
    public function createCrease(string $field, string $op, $diff): string
    {
        // 检查参数
        if ($op != '-') {
            $op = '+';
        }

        // 字段名规范化
        $field = $this->markField($field);

        return $field . ' = ' . $field . ' ' . $op . ' ' . $diff;
    }

    /**
     * 构造Update语句中的赋值部分
     * @param string $field 字段名
     * @param mixed $value 值
     * @return string
     */
    public function createSet(string $field, $value): string
    {
        $field = $this->markField($field);
        return $field . ' = ' . $value;
    }

    /**
     * 处理字段名值数组,名会加上定界符,值不会加定界符
     * 被修改类操作使用
     *
     * @param array $row 行数据
     * @return array [字段名列表,值列表]
     * @throws MysqlException
     */
    public function createRow(array $row): array
    {
        // 更新时必须指定要更新的数据
        if (!$row) {
            throw new MysqlException('更新时必须指定数据', MysqlException::MISS_DATA_IN_MODIFY);
        }

        // 要更新的数据必须以对象或数组的方式提供
        if (!is_array($row)) {
            trigger_error('更新时数据类型错误:' . json($row), E_USER_ERROR);
        }

        $fields = $values = [];
        foreach ($row as $name => $value) {
            // 更新的数据中,列名必须是字符串
            if (!is_string($name) or is_numeric($name)) {
                trigger_error('更新时字段名必须是字符串:' . json($name), E_USER_ERROR);
            }

            // 如果值为空,则存储空字符串
            if (is_null($value) or (is_bool($value) and !$value)) {
                $value = '';
            }

            // 值只能是字符串或数值
            if (!is_string($value) and !is_numeric($value)) {
                trigger_error('更新时字段的值必须是字符串或数值:' . json($value), E_USER_ERROR);
            }

            // 名加上定界符,值不加(可能是表达式)
            $fields[] = $this->markField($name);
            $values[] = $value;
        }

        // 没有有效的更新数据
        if (!count($fields)) {
            throw new MysqlException('更新时没有有效的数据:' . json($row), MysqlException::INVALID_MODIFY);
        }

        return [$fields, $values];
    }
}