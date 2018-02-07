<?php
require_once __DIR__.'/../vendor/autoload.php';

$service = new \Ouarea\Payment\IndustrialBankJs\Service([
    'mch_id'     => '',
    'sub_appid'  => '',
    'key'        => '',
    'notify_url' => '',
]);

$outTradeNo = quick_random(16);
$result = $service->placeOrder([
    'out_trade_no'  => $outTradeNo,
    'total_fee'     => 1,
    'body'          => '账单' . $outTradeNo,
    'attach'        => '账单' . $outTradeNo,
    'mch_create_ip' => '192.168.0.1',
    'time_start'    => date('YmdHis'),
    'time_expire'   => date('YmdHis', time() + 600),
    'sub_openid'    => null,
    'is_minipg'     => '0',
]);

var_dump($result);

/*
object(stdClass)#28 (4) {
  ["message"]=>NULL
  ["code"]=>int(0)
  ["pay_info"]=>string(239) "{"appId":"","timeStamp":"","status":"0","signType":"MD5","package":"","callback_url":null,"nonceStr":"","paySign":""}"
  ["token_id"]=>string(33) ""
}
 */


