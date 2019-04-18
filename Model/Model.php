<?php
/*
 * Model驱动类，需要安装pdo扩展
 * @athor    jim
 * @date    2019-04-17
 */
class Model
{
    private $_pdo = null;
    /*
     * 构造器
     *
     */
    public function __construct()
    {

        if ($this->_pdo == null) {
            $dsn = 'mysql:host=localhost;dbname=test';
            try {
                $this->_pdo = new PDO($dsn, 'root', 'Sjj19930515.');
            } catch (PDOException $e) {
                echo '数据库连接失败' . $e->getMessage();
                exit;
            }
        }
    }
    /*
     * pdo handler
     *
     */
    public function getHandler()
    {
        return $this->_pdo;
    }

    /*
     * 查询sql
     *
     */
    public function query($sql)
    {
		$handel = $this->_pdo->prepare($sql);
		$handel->execute();
		$row=$handel->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    /*
     * 执行sql
     *
     */
    public function exect($sql)
    {
        $result = $this->_pdo->exec($sql);
        return $result;
    }
}
