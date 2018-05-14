<?php

namespace Ouarea\Payment\IndustrialBankJs;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

class Service
{
    const UNIFIED_ORDER_URL = 'https://pay.swiftpass.cn/pay/gateway';

    /**
     * commercial tenant (商户) id
     *
     * @var string
     */
    protected $merchantId;

    /**
     * appid of weixin-mp or weixin-mini-program
     *
     * @var string
     */
    protected $subAppid;

    /**
     * version of interface
     * 
     * @var string
     */
    protected $version;

    /**
     * used for generating signature for transaction
     *
     * @var string
     */
    protected $key;

    /**
     * url to be notified when trade status changes
     *
     * @var string
     */
    protected $notifyUrl;
    
    /**
     * http client
     * @var \GuzzleHttp\ClientInterface
     */
    private $client;

    public function __construct(array $config, ClientInterface $client = null)
    {
        $this->merchantId = array_get($config, 'mch_id');
        $this->subAppid   = array_get($config, 'sub_appid');
        $this->version    = array_get($config, 'version', '2.0');
        $this->key        = array_get($config, 'key');
        $this->notifyUrl  = array_get($config, 'notify_url');
        $this->client     = $client ?: $this->createDefaultHttpClient();
    }

    /**
     * prepare the trade by placing an unified order
     *
     * @param array $params    params for the unified order,  array keys taken:
     *                              - out_trade_no  (string)  order number of merchant
     *                              - total_fee     (integer) payment total fee, measured in 'fen'
     *                              - body          (string)  order's description
     *                              - attach        (string)  a transparent value, wxpay will transfer back this value
     *                                                        without any changes
     *                              - mch_create_ip (string)  ip of user who request this payment. format as: 8.8.8.8
     *                              - time_start    (string)  yyyMMddhhmmss
     *                              - time_expire   (string)  yyyMMddhhmmss
     *                              - sub_openid    (string)  openid of user in weixin-mp or weixin-mini-program,
     *                                                        not provider if this param test
     *                              - is_minipg     (integer) 1 if request is from weixin-mini-program
     *
     * @return \stdClass       the trade info. with following fields set:
     *                              - code        error code. 0 for no error
     *                              - message     description for error message
     *                                            the following fields are available if it succeeded
     *                              - pay_info    string of json format, return when is_raw is 1
     *                              - token_id    id of the prepare order created by industrial bank pay
     */
    public function placeOrder($params)
    {
        $this->padCommonParams($params);
        $params = array_filter($params); // wxpay doesn't like either null or empty param

        $params['sign'] = $this->signRequest($params);

        return $this->postUnifiedOrderRequestAndParse($params);
    }

    /**
     * pad common params for request
     *
     * @param array $params
     * @return mixed
     */
    private function padCommonParams(&$params)
    {
        $params['mch_id']           = $this->merchantId;
        $params['sub_appid']        = $this->subAppid;
        $params['version']          = $this->version;
        $params['charset']          = 'UTF-8';
        // $params['sign_type']        = 'MD5'; // 银联限制不允许使用MD5方式
        // 随机字符串，必填项，不长于 32 位
        $params['nonce_str']        = $this->genNonceStr();
        // 接口类型：pay.weixin.native
        $params['service']          = 'pay.weixin.jspay';
        // 是否支持信用卡，1为不支持，0为支持
        $params['limit_credit_pay'] = '0';
        // 通知地址，必填项，接收威富通通知的URL，需给绝对路径，255字符内格式如:http://wap.tenpay.com/tenpay.asp
        $params['notify_url']       = $this->notifyUrl;
        // 原生JS
        $params['is_raw']           = '1';
        // $params['device_info']      = ''; // 终端设备号
        // $params['goods_tag']        = ''; // 微信平台配置的商品标记，用于优惠券或者满减使用
    }

    // generate nonce string
    protected function genNonceStr($length = 32)
    {
        return quick_random($length);
    }

    /**
     * Generate request params signature
     *
     * @param array $request
     *
     * @return string signed text
     */
    protected function signRequest(array $request)
    {
        // sort $data by key and convert to string
        ksort($request);
        reset($request);

        $raw = self::implode($request) . '&key=' . $this->key;

        // md5 and uppercase the result
        return strtoupper(md5($raw));   
    }

    /*
     * implode associated array while keeping both its keys and values intact, and
     * this is the reason why http_build_query is not used
     */
    protected static function implode(array $assoc, $inGlue = '=', $outGlue = '&')
    {
        $imploded = '';
        foreach ($assoc as $name => $value) {
            $imploded .=  $name . $inGlue . $value . $outGlue;
        }

        return substr($imploded, 0, -strlen($outGlue));
    }

    private function postUnifiedOrderRequestAndParse($params)
    {
        $xml = $this->postRequest(self::UNIFIED_ORDER_URL, $params);

        $result = new \stdClass();
        $result->message = $this->parseResponseForMessage($xml);
        if ($xml->status == 0) {
            // verify the response signature
            $this->ensureResponseNotForged(xml_to_array($xml));

            $result->code = 0;
            $result->pay_info = (string) $xml->pay_info;
            $result->token_id = (string) $xml->token_id;
        } else {
            $result->code = $this->parseResponseForCode($xml);
        }

        return $result;
    }

    /**
     * post xml request
     *
     * @params string $url
     * @params array $params
     */
    private function postRequest($url, $params)
    {
        $options = [
            RequestOptions::BODY => $this->xmlize($params)
        ];

        $response = $this->client->request('POST', $url, $options);
        if ($response->getStatusCode() != 200) {
            throw new \Exception('bad response from industrial bank pay: ' . (string)$response->getBody());
        }

        // Sometimes mch_id is not return.
        // <xml>
        //     <charset><![CDATA[UTF-8]]></charset>
        //     <mch_id><![CDATA[755437000006]]></mch_id>
        //     <nonce_str><![CDATA[roZGQ8qW7TmL2AqSKTrR3UInOnphQ410]]></nonce_str>
        //     <services><![CDATA[pay.weixin.jspay|pay.weixin.micropay|pay.weixin.native|pay.weixin.app]]></services>
        //     <sign><![CDATA[20856358231A40074077C4974280FA2F]]></sign>
        //     <sign_type><![CDATA[MD5]]></sign_type>
        //     <status><![CDATA[0]]></status>
        //     <token_id><![CDATA[934000685c54b40b23996d4e96fbe0ce]]></token_id>
        //     <version><![CDATA[2.0]]></version>
        // </xml>

        // Error message
        // <xml>
        //     <version><![CDATA[2.0]]></version>
        //     <charset><![CDATA[UTF-8]]></charset>
        //     <status><![CDATA[400]]></status>
        //     <message><![CDATA[Parse xml error,please use UTF-8 encoded]]></message>
        // </xml>

        $xml = simplexml_load_string((string) $response->getBody());
        if (!isset($xml->status) || is_null($xml->status)) {
            throw new \Exception('bad response from industrial bank pay for js: ' . (string)$response->getBody());
        }

        return $xml;
    }

    private function xmlize(array $data)
    {
        $xml = '<xml>';
        foreach ($data as $k => $v) {
            if (is_string($v)) { // prevent accidental text in $v, use CDATA for it
                $xml .= sprintf('<%s><![CDATA[%s]]></%s>', $k, $v, $k);
            } else {
                $xml .= sprintf('<%s>%s</%s>', $k, $v, $k);
            }
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * ensure that response comes from Wxpay.
     *
     * @param array $response    the notification to verify
     * @param string $signParam  the sign key
     * @param bool $keepSign     false to eliminate sign in the response before comparing
     *
     * @throws \Exception
     */
    protected function ensureResponseNotForged(array $response,
                                               $signParam = 'sign',
                                               $keepSign = false)
    {
        $sign = isset($response[$signParam]) ? $response[$signParam] : null;
        if (empty($sign)) {
            throw new \Exception('Forged trade notification');
        }

        if (!$keepSign) {
            unset($response[$signParam]);
        }

        if ($sign != $this->signResponse($response)) {
            throw new \Exception('Signature verification failed');
        }
    }

    /**
     * Generate response params signature
     *
     * @param array $response
     *
     * @return string signed text
     */
    protected function signResponse(array $response)
    {
        // sort $data by key and convert to string
        ksort($response);
        reset($response);

        $raw = self::implode($response) . '&key=' . $this->key;

        // md5 and strtolower the result
        // return strtolower(md5($raw));
        return strtoupper(md5($raw));
    }

    private function parseResponseForMessage($xml, $default = null)
    {
        if (isset($xml->message)) {
            return (string) $xml->message;
        }

        return $default;
    }

    // helper method to parse response for code
    private function parseResponseForCode($xml, $default = '1')
    {
        if (isset($xml->status)) {
            return (string) $xml->status;
        }

        return $default;
    }

    /*
     * create default http client
     *
     * @return Client
     */
    private function createDefaultHttpClient()
    {
        return new Client();
    }

    /**
     * called when a trade's status changes (asynchronously)
     * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=9_7
     *
     * @param array|string $notification   notification (typically the whole $_POST) from Wxpay
     * @param callable $callback    callback will be passed, the parsed trade as its first param with following
     *                              attributes:
     *                              - code         'SUCCESS' for trade success.
     *                              - orderNo      the order# related to the trade
     *                              - tradeType    trade type, such as APP, NATIVE, JSAPI etc.
     *                              - fee          total fee user paid
     *                              - transId      the order# in wx's system
     *                              - paidAt       payment end time. format: yyyyMMddHHmmss
     *
     *                              on error, the second param will be passed in with following attributes:
     *                              - code         error code
     *                              - message      error message
     *
     * @return string               xml text to indicate the notification is successfully or failed to be handled
     *
     * @throws \Exception exception will thrown in case of invalid signature
     *                              or bad trade status
     */
    public function tradeUpdated($notification, callable $callback)
    {
        if (is_string($notification)) { // for string notification
            $notification = $this->morphNotification($notification);
        }

        if ($notification['status'] != 0) {
            // callback with an error
            try {
                call_user_func($callback, null, (object)[
                    'code'    => $notification['status'],
                    'message' => array_get($notification, 'message')
                ]);
            } catch (\Throwable $ex) {
                // ignore any exceptions
            }

            return 'failure';
        }

        if ($notification['result_code'] != 0) {
            // callback with an error
            try {
                call_user_func($callback, null, (object)[
                    'code'    => array_get($notification, 'err_code', $notification['result_code']),
                    'message' => array_get($notification, 'message')
                ]);
            } catch (\Throwable $ex) {
                // ignore any exceptions
            }

            return 'failure';
        }

        if ($notification['pay_result'] != 0) {
            // callback with an error
            try {
                call_user_func($callback, null, (object)[
                    'code'    => $notification['pay_result'],
                    'message' => array_get($notification, 'pay_info')
                ]);
            } catch (\Throwable $ex) {
                // ignore any exceptions
            }

            return 'failure';
        }

        $this->ensureResponseNotForged($notification);
        $trade = $this->parseTradeUpdateNotification($notification);

        try {
            if (call_user_func($callback, $trade, null)) {
                return 'success';
            }
        } catch (\Throwable $ex) {
            // ignore any exceptions, and let the rest of the code to respond error
        }

        return 'failure';
    }

    /**
     * morph the notification (as array)
     *
     * @param string $notification
     * @return array
     */
    private function morphNotification($notification)
    {
        // the notification can be xml string
        $morphed = $this->xml2array($notification);
        if ($morphed !== false) {
            return $morphed;
        }

        // or it can be http query string
        parse_str($notification, $morphed);
        return $morphed;
    }

    /**
     * convert xml to array
     *
     * @param string $xml
     * @return array|bool   false if it's not valid xml
     */
    private function xml2array($xml)
    {
        $xmlEl = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA|LIBXML_NOERROR|LIBXML_NOWARNING);
        if ($xmlEl === false) {
            return false;
        }

        return json_decode(json_encode($xmlEl), TRUE);
    }

    private function parseTradeUpdateNotification(array $notification)
    {
        $trade = new \stdClass();
        $trade->code      = 0;
        $trade->mchId     = $notification['mch_id'];
        $trade->tradeType = $notification['trade_type']; // 如: pay.weixin.jspay
        $trade->transId   = $notification['transaction_id']; // 兴业交易号
        $trade->orderNo   = $notification['out_trade_no'];
        $trade->fee       = $notification['total_fee'];
        $trade->paidAt    = $notification['time_end']; // 兴业银行的支付完成时间:20091227091010
        // $trade->subOpenid = $notification['sub_openid'];
        return $trade;
    }
}