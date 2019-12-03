<?php
/**
 * redis + rabbitmq 实现分布式锁
 * Created by PhpStorm.
 * User: austin
 * Date: 2019/12/3
 * Time: 2:57 PM
 */

$sku_id = 1;

$redis = get_redis();
$lock_key  = 'LOCK_SKU_' . $sku_id;
$stock_key = 'SKU_STOCK_' . $sku_id;

$stock = $redis->get($stock_key);
if ($stock > 0) {
    $res = $redis->set($lock_key, 1, ['nx', 'px' => 300]);

    if (!$res) {
        echo "系统繁忙，请稍后再试! \n";
        exit();
    }

    usleep(rand(100, 1000));

    $order_code = randon_code(32);
    $mysql = get_mysql();
    $mysql->autocommit(false);

    $insert_order = sprintf("insert into `order` (order_code, sku_id) values ('%s', %d)",$order_code, $sku_id);
    $update_sku = sprintf('update sku set stock = stock - 1 where id = %d and status = %d and stock = %d',$sku_id, 1, $stock);

    $mysql->begin_transaction();

    $insert_order_res = $mysql->query($insert_order);
    $insert_order_rows = $mysql->affected_rows;

    $update_sku_res  = $mysql->query($update_sku);
    $update_sku_rows = $mysql->affected_rows;

    if ($insert_order_res &&
        $insert_order_rows == 1 &&
        $update_sku_res &&
        $update_sku_rows == 1) {
        $stock -= 1;
        $is_set_redis_stock_success = $redis->set($stock_key, $stock);
        if ($is_set_redis_stock_success) {
            $mysql->commit();
            $mysql->autocommit(true);
        } else {
            $mysql->rollback();
            exit('redis库存删减失败，回滚');
        }
    } else {
        $mysql->rollback();
        exit('mysql事务执行失败，回滚');
    }


    echo "扣减成功, 当前剩余库存 {$stock} \n";
    error_log("扣减成功, 当前剩余库存 {$stock}");
    $redis->del($lock_key);
    exit();

}

echo "库存不足! \n";
error_log("库存不足!");
exit();


function get_redis() {
    $host = '127.0.0.1';
    $auth = 'redis';
    $redis = new Redis();
    $redis->connect($host);
    $redis->auth($auth);
    $result = $redis->ping();
    if ($result != '+PONG') {
        exit('redis 连接失败: ' . $redis->getLastError());
    }
    return $redis;
}

function get_mysql() {
    $host = '127.0.0.1';
    $user='root';
    $password='123456';
    $db_name='test';

    $mysql = new mysqli($host,$user,$password,$db_name);

    if ($mysql->connect_error) {
        exit('mysql 连接失败: ' . $mysql->connect_error);
    }

    return $mysql;
}

function randon_code($len = 16) {
    $ori = sha1(uniqid() . microtime(true));
    return substr(str_shuffle($ori), 0, $len);
}