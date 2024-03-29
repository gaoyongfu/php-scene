<?php
/**
 * redis + rabbitmq 实现分布式锁, 防止超卖
 *
 * Created by PhpStorm.
 * User: 渊虹
 * Date: 2019/12/3
 * Time: 2:57 PM
 */

require_once '../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$start_time = microtime(true);
$sku_id = 1;

$redis = get_redis();
$lock_key  = 'LOCK_SKU_' . $sku_id;
$stock_key = 'SKU_STOCK_' . $sku_id;

$stock = $redis->get($stock_key);
if ($stock > 0) {
    $res = $redis->set($lock_key, 1, ['nx', 'px' => 200]);
    if (!$res) {
        done($start_time,-1);
    }

    // 延时器
    pttl_monitor($lock_key,200);

    usleep(rand(100, 500) * 1000);

    $order_code = randon_code(32);
    $mysql = get_mysql();
    $mysql->autocommit(false);

    $insert_order_tpl = 'insert into `order` (order_code, sku_id) values ("%s", %d)';
    $insert_order = sprintf($insert_order_tpl,$order_code, $sku_id);

    $update_sku_tpl = 'update sku set stock = stock - 1 where id = %d and status = %d and stock = %d';
    $update_sku = sprintf($update_sku_tpl,$sku_id, 1, $stock);

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
            done($start_time,-2);
        }
    } else {
        $mysql->rollback();
        done($start_time,-3);
    }

    $redis->del($lock_key);

    done($start_time,1, $stock);
}

done($start_time);


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

function pttl_monitor($key, $pttl) {
    echo $key;
    $mq = get_rabbitmq();

    $ch = $mq->channel();
    $ch->queue_declare('ttl_monitor', false, false, false, false);
    $msg_json = json_encode(array('redis_key' => $key, 'pttl' => $pttl));
    $msg = new AMQPMessage($msg_json);
    $ch->basic_publish($msg, '', 'ttl_monitor');

    $ch->close();
    $mq->close();

}

function get_rabbitmq() {
    $host = '127.0.0.1';
    $port = 5672;
    $username = 'admin';
    $password = 'admin';
    $vhost    = 'my_vhost';

    return new AMQPStreamConnection($host, $port, $username, $password, $vhost);
}

function randon_code($len = 16) {
    $ori = sha1(uniqid() . microtime(true));
    return substr(str_shuffle($ori), 0, $len);
}

function done($start_time, $status = 0, $stock = 0) {
    switch ($status) {
        case 1:
            $msg = "扣减成功, 当前剩余库存: " . $stock;
            break;
        case -1:
            $msg = "系统繁忙, 请稍后再试";
            break;
        case -2:
            $msg = "redis库存删减失败，回滚";
            break;
        case -3:
            $msg = "mysql事务执行失败，回滚";
            break;
        default:
            $msg = "库存不足";
    }
    error_log($msg);
    error_log("运行时间: " . (microtime(true) - $start_time) * 1000 . '毫秒');
    echo $msg;
    exit();
}