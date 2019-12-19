<?php
/**
 * Created by PhpStorm.
 * User: 渊虹
 * Date: 2019/12/5
 * Time: 10:52 PM
 */

require_once '../vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

$mq = get_rabbitmq();

$ch = $mq->channel();
$ch->queue_declare('ttl_monitor', false, false, false, false);

$ch->basic_consume('ttl_monitor', '', false, true, false, false, function ($msg) {
    $msg = json_decode($msg->body, true);
    $redis_key = $msg['redis_key'];
    $ori_pttl = $msg['pttl'];

    $redis = get_redis();
    while(true) {
        $current_pttl = $redis->pttl($redis_key);
        if ($current_pttl < 0) {
            break;
        }

        if (($ori_pttl - $current_pttl) >= $ori_pttl / 3) {
            $pttl = $ori_pttl + $current_pttl;
            echo "key = $redis_key 延长过期时间, 当前剩余过期时间 = $current_pttl 毫秒, 延长后的剩余过期时间 = $pttl 毫秒" . PHP_EOL;
            $redis->pExpire($redis_key,$pttl);
        }
    }

});

echo ' [*] ttl monitor running. To exit press CTRL+C', "\n";

while(count($ch->callbacks)) {
    $ch->wait();
}

function get_rabbitmq() {
    $host     = '127.0.0.1';
    $port     = 5672;
    $username = 'admin';
    $password = 'admin';
    $vhost    = 'my_vhost';

    return new AMQPStreamConnection($host, $port, $username, $password, $vhost);
}

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