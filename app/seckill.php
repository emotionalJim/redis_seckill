<?php

/**********************************************
 * 抢购模块
 *
 * @author liubin
 * @date 2016-02-10
 *
 * ab -n 1000 -c 100 http://192.168.16.73/Seckill/buy.php
 *
 */
class seckill extends common
{

    private $_orderModel = null;
    private $_goodsModel = null;
    private $_logModel = null;
    private $_redis = null;

    /*
     * 错误信息
     */
    protected $_error = '';

    /**
     * 构造器
     *
     */
    public function __construct()
    {
        if ($this->_orderModel === null) {
            $this->_orderModel = new OrderModel();
        }

        if ($this->_goodsModel === null) {
            $this->_goodsModel = new GoodsModel();
        }

        if ($this->_redis === null) {
            $this->_redis = new QRedis();
        }

        if ($this->_logModel === null) {
            $this->_logModel = new LogModel();
        }
    }

    /*
     * 秒杀API
     *
     * @author liubin
     * @date 2017-02-10
     */
    public function addQsec()
    {

        $gid = intval($_GET['gid']);
        $type = isset($_GET['type']) ? $_GET['type'] : 'mysql';
        switch ($type) {
            case 'mysql':
                $this->order_check_mysql($gid);
                echo $this->getError();
                break;
            case 'redis':
                $this->order_check_redis($gid);
                echo $this->getError();
                break;
            case 'transaction':
                $this->order_check_transaction($gid);
                echo $this->getError();
                break;
            default:
                echo '类型错误';
                break;
        }

    }

    /*
     * 获取错误信息
     *
     * @author liubin
     * @date 2017-02-10
     */
    public function getError()
    {
        return $this->_error;
    }

    /*
     * 基于mysql验证库存信息
     * @desc 高并发下会导致超卖
     *
     * @author liubin
     * @date 2017-02-10
     */
    protected function order_check_mysql($gid)
    {

        $model = $this->_goodsModel;
        $pdo = $model->getHandler();
        $gid = intval($gid);

        /*
         * 1：$sql_forlock如果不加事务，不加写锁：
         * 超卖非常严重，就不说了
         *
         * 2：$sql_forlock如果不加事务，只加写锁：
         * 第一个会话读$sql_forlock时加写锁，第一个会话$sql_forlock查询结束会释放该行锁.
         * 第二个会话在第一个会话释放后读$sql_forlock的写锁时，会再次$sql_forlock查库存
         * 导致超卖现象产生
         *
         */
        $sql_forlock = 'select * from goods where id = ' . $gid . ' limit 1 for update';
        //$sql_forlock    = 'select * from goods where id = '.$gid .' limit 1';
        $result = $pdo->query($sql_forlock, PDO::FETCH_ASSOC);
        $goodsInfo = $result->fetch();

        if ($goodsInfo['counts'] > 0) { //有库存

            //去库存
            $gid = $goodsInfo['id'];
            $sql_inventory = 'UPDATE goods SET counts = counts - 1 WHERE id = ' . $gid;
            $result = $this->_goodsModel->exect($sql_inventory);

            if ($result) {
                //创订单
                $data = [];
                $data['order_id'] = $this->_orderModel->buildOrderNo();
                $data['goods_id'] = $goodsInfo['id'];
                $data['addtime'] = time();
                $data['uid'] = 1;
                $order_rs = $this->_orderModel->create_order($data);
                if ($order_rs) {
                    $this->_error = '购买成功';
                    return true;
                }
            }
        }

        $this->_error = '库存不足';
        return false;

    }

    /*
     * 基于redis队列验证库存信息
     * @desc Redis是底层是单线程的,命令执行是原子操作,包括lpush,lpop等.高并发下不会导致超卖
     *
     * @author liubin
     * @date 2017-02-10
     */
    protected function order_check_redis($gid)
    {
        $goodsInfo = $this->_goodsModel->getGoods($gid); //获取商品信息，一维
        if (!$goodsInfo) {
            $log_data = [
                'goods_id' => $goodsInfo['id'],
                'num' => 0,
                'add_time' => time(),
                'des' => '商品不存在',
            ];
            $this->_logModel->add($log_data);
            return false;
        }
        $key = 'goods_list_' . $goodsInfo['id'];
		$check = $this->_redis->getHandel()->lpop($key); //左边截取一个，看是否存在
        if (!$check) {
            $log_data = [
                'goods_id' => $goodsInfo['id'],
                'num' => 0,
                'add_time' => time(),
                'des' => '库存不足',
            ];
            $this->_logModel->add($log_data); //库存不足插入日志
            return false;
        }
		//生成订单
        $data = [];
        $data['order_id'] = $this->_orderModel->buildOrderNo(); //生成订单号
        $data['goods_id'] = $goodsInfo['id'];
        $data['addtime'] = time();
        $data['uid'] = 1;
        $this->_orderModel->create_order($data); //添加订单
		//库存减少
        $gid = $goodsInfo['id'];
        $sql = 'UPDATE goods SET counts = counts - 1 WHERE id = ' . $gid;
        $this->_goodsModel->exect($sql); //商品库存-1
        $left_count = $this->_redis->getHandel()->lsize($key); //当前列表数量，此数量为从左边截取过后的剩余数量
        $log_data = [
            'goods_id' => $goodsInfo['id'],
            'add_time' => time(),
            'num' => $left_count,
            'des' => '购买成功',
        ];
        $this->_logModel->add($log_data);
        echo '购买成功';
        return true;
    }

	
    /*
     * 基于mysql事务验证库存信息
     * @desc 事务 和 行锁 模式,高并发下不会导致超卖，但效率会慢点

    说明：
    如果$sql_forlock不加写锁，并发时，$sql_forlock查询的记录存都大于0，可以减库存操作.
    如果$sql_forlock加了写锁，并发时，$sql_forlock查询是等待第一次链接释放后查询.所以库存最多就是5

     */
    protected function order_check_transaction($gid)
    {

        $model = $this->_goodsModel;
        $pdo = $model->getHandler();
        $gid = intval($gid);

        try {

            $pdo->beginTransaction(); //开启事务处理

            /*
             * 1：$sql_forlock如果只加事务，不加写锁：
             * 开启事务
             * 因为没有加锁，读$sql_forlock后，并发时$sql_inventory之前还可以再读。
             * $sql_inventory之后和commit之前才会锁定
             * 出现超卖跟事务的一致性不冲突
             *
             *
             * 2：$sql_forlock如果加了事务，又加读锁：
             * 开启事务
             * 第一个会话读$sql_forlock时加读锁，并发时，第二个会话也允许获得$sql_forlock的读锁，
             * 但是在第一个会话执行去库存操作时（写锁），写锁便会等待第二个会话的读锁，第二个会话执行写操作时，写锁便会等待第一个会话的读锁，
             * 出现死锁

             * 3：$sql_forlock如果加了事务，又加写锁：
             * 开启事务
             * 第一个会话读$sql_forlock时加写锁，直到commit才会释放写锁，并发查询不会出现超卖现象。
             *
             */

            $sql_forlock = 'select * from goods where id = ' . $gid . ' limit 1 for update';
            //$sql_forlock        = 'select * from goods where id = '.$gid .' limit 1 LOCK IN SHARE MODE';
            //$sql_forlock        = 'select * from goods where id = '.$gid .' limit 1';
            $result = $pdo->query($sql_forlock, PDO::FETCH_ASSOC);
            $goodsInfo = $result->fetch();

            if ($goodsInfo['counts'] > 0) {

                //去库存
                $gid = $goodsInfo['id'];
                $sql_inventory = 'UPDATE goods SET counts = counts - 1 WHERE id = ' . $gid;
                $result = $this->_goodsModel->exect($sql_inventory);

                if (!$result) {
                    $pdo->rollBack();
                    $this->_error = '库存减少失败';
                    return false;
                }

                //创订单
                $data = [];
                $data['id'] = 'null';
                $data['order_id'] = $this->_orderModel->buildOrderNo();
                $data['goods_id'] = $goodsInfo['id'];
                $data['uid'] = 'abc';
                $data['addtime'] = time();

                $sql = 'insert into orders (id,order_id,goods_id,uid,addtime) values (' . $data['id'] . ',"' . $data['order_id'] . '","' . $data['goods_id'] . '","' . $data['uid'] . '","' . $data['addtime'] . '")';
                $result = $pdo->exec($sql);

                if (!$result) {
                    $pdo->rollBack();
                    $this->_error = '订单创建失败';
                    return false;
                }

                $pdo->commit(); //提交
                $this->_error = '购买成功';
                return true;

            } else {
                $this->_error = '库存不足';
                return false;
            }
        } catch (PDOException $e) {
            echo $e->getMessage();
            $pdo->rollBack();
        }

    }

    /*
     * 创建订单
     * mysql 事物处理，也可以用存储过程
     *
     */
    private function create_order($goodsInfo)
    {
        //生成订单
        $data = [];
        $data['order_id'] = $this->_orderModel->buildOrderNo();
        $data['goods_id'] = $goodsInfo['id'];
        $data['addtime'] = time();
        $data['uid'] = 1;
        $order_rs = $this->_orderModel->create_order($data);

        //库存减少
        $gid = $goodsInfo['id'];
        $sql = 'UPDATE goods SET counts = counts - 1 WHERE id = ' . $gid;
        $result = $this->_goodsModel->exect($sql);
        return true;
    }

}
