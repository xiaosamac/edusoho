<?php

namespace Topxia\MobileBundleV2\Processor\Impl;

use ApiBundle\Api\ApiRequest;
use ApiBundle\Api\ResourceKernel;
use AppBundle\Common\ArrayToolkit;
use Biz\OrderFacade\Exception\OrderPayCheckException;
use Codeages\Biz\Framework\Pay\Service\PayService;
use Topxia\MobileBundleV2\Processor\BaseProcessor;
use Biz\Order\OrderProcessor\OrderProcessorFactory;
use Topxia\MobileBundleV2\Processor\OrderProcessor;
use Topxia\MobileBundleV2\Alipay\MobileAlipayConfig;

class OrderProcessorImpl extends BaseProcessor implements OrderProcessor
{
    public function getPaymentMode()
    {
        $coinSetting = $this->controller->setting('coin');

        $coinEnabled = true;
        if (empty($coinSetting)) {
            $coinEnabled = false;
        }

        $coinEnabled = isset($coinSetting['coin_enabled']) && $coinSetting['coin_enabled'];

        $payment = $this->controller->setting('payment', array());

        $apipayEnabled = true;
        if (empty($payment['enabled'])) {
            $apipayEnabled = false;
        }

        if (empty($payment['alipay_enabled'])) {
            $apipayEnabled = false;
        }

        if (empty($payment['alipay_key']) || empty($payment['alipay_secret']) || empty($payment['alipay_account'])) {
            $apipayEnabled = false;
        }

        //0 can buy
        $magicSetting = $this->getSettingService()->get('magic', array());
        $iosBuyDisable = isset($magicSetting['ios_buy_disable']) ? $magicSetting['ios_buy_disable'] : 0;

        return array(
            'coin' => $coinEnabled,
            'alipay' => $apipayEnabled,
            'ios_buy_disable' => $iosBuyDisable == 0,
        );
    }

    public function validateIAPReceipt()
    {
        $user = $this->controller->getUserByToken($this->request);

        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '您尚未登录');
        }

        $receipt = $this->getParam('receipt-data');
        $amount = $this->getParam('amount', 0);
        $transactionId = $this->getParam('transaction_id', false);


        $data = array(
            'receipt' => $receipt,
            'amount' => $amount,
            'transaction_id' => $transactionId,
            'is_sand_box' => false,
            'user_id' => $user['id']
        );

        $trade = $this->getPayService()->rechargeByIap($data);

        return array(
            'status' => true,
        );

//        return $this->requestReceiptData($user['id'], $amount, $receipt, $transactionId, false);
    }

    private function del_checkCoupon()
    {
        $code = $this->getParam('code');
        $type = $this->getParam('targetType');
        $id = $this->getParam('targetId');

        if (!in_array($type, array('course', 'vip', 'classroom'))) {
            return $this->createErrorResponse('error', '优惠码不支持的购买项目。');
        }

        $price = $this->getParam('amount');

        try {
            $couponInfo = $this->getCouponService()->checkCouponUseable($code, $type, $id, $price);
        } catch (\Exception $e) {
            return $this->createErrorResponse('error', $e->getMessage());
        }

        return $couponInfo;
    }

    private function getPayOrderInfo($targetType, $targetId)
    {
        $payOrderInfo = array();

        if ('course' == $targetType) {
            $course = $this->controller->getCourseService()->getCourse($targetId);

            if ($course['status'] != 'published') {
                return $this->createErrorResponse('course_close', '课程已关闭');
            }

            $courseSet = $this->getCourseSetService()->getCourseSet($course['courseSetId']);

            $picture = empty($courseSet['cover']['middle']) ? '' : $courseSet['cover']['middle'];
            $payOrderInfo = array(
                'title' => $course['title'],
                'price' => $course['price'],
                'picture' => $this->coverPic($picture, 'course.png'),
            );
        } elseif ('classroom' == $targetType) {
            $classroom = $this->getClassroomService()->getClassRoom($targetId);

            if (empty($classroom)) {
                return $this->createErrorResponse('no_classroom', 'no_classroom');
            }

            $payOrderInfo = array(
                'title' => $classroom['title'],
                'price' => $classroom['price'],
                'picture' => $this->coverPic($classroom['middlePicture'], 'classroom.png'),
            );
        } elseif ('vip' == $targetType) {
            $result = $this->getVipOrderInfo($targetId);

            if (isset($result['error'])) {
                return $this->createErrorResponse($result['error']['name'], $result['error']['message']);
            }

            $payOrderInfo = $result;
        }

        return $payOrderInfo;
    }

    private function getVipOrderInfo($levelId)
    {
        if (!$this->controller->isinstalledPlugin('Vip')) {
            return $this->createErrorResponse('no_vip', '网校未安装vip插件');
        }

        $level = $this->getLevelService()->getLevel($levelId);

        $buyType = $this->controller->setting('vip.buyType');

        if (empty($buyType)) {
            $buyType = 10;
        }

        return array(
            'level' => $level,
            'buyType' => $buyType,
        );
    }

    public function getPayOrder()
    {
        $user = $this->controller->getUserByToken($this->request);

        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '您尚未登录');
        }

        $targetId = $this->getParam('targetId', 0);
        $targetType = $this->getParam('targetType');

        if (empty($targetId)) {
            return $this->createErrorResponse('not_tergetId', '没有发现购买内容！');
        }

        $payOrderInfo = $this->getPayOrderInfo($targetType, $targetId);

        if (isset($payOrderInfo['error'])) {
            return $this->createErrorResponse('error', '没有发现购买内容！');
        }

        $userProfile = $this->controller->getUserService()->getUserProfile($user['id']);

        foreach ($userProfile as $key => $value) {
            if (!in_array($key, array(
                'truename', 'id', 'mobile', 'qq', 'weixin', ))) {
                unset($userProfile[$key]);
            }
        }

        $coin = $this->getCoinSetting();

        return array(
            'userProfile' => $userProfile,
            'orderInfo' => $payOrderInfo,
            'coin' => $coin,
            'isInstalledCoupon' => $this->controller->isinstalledPlugin('Coupon'),
        );
    }

    private function getCoinSetting()
    {
        $coinSetting = $this->controller->setting('coin');

        if (empty($coinSetting)) {
            return null;
        }

        $coinEnabled = isset($coinSetting['coin_enabled']) && $coinSetting['coin_enabled'];

        if (empty($coinEnabled)) {
            return null;
        }

        $cashRate = 1;

        if (isset($coinSetting['cash_rate'])) {
            $cashRate = $coinSetting['cash_rate'];
        }

        $coin = array(
            'cashRate' => $cashRate,
            'priceType' => isset($coinSetting['price_type']) ? $coinSetting['price_type'] : null,
            'name' => isset($coinSetting['coin_name']) ? $coinSetting['coin_name'] : '虚拟币',
        );

        return $coin;
    }

    //amount改为有方法体内自己计算，不信任URL传参
    private function requestReceiptData($userId, $amount, $receipt, $transactionId, $isSandbox = false)
    {
        if ($isSandbox) {
            $endpoint = 'https://sandbox.itunes.apple.com/verifyReceipt';
        } else {
            $endpoint = 'https://buy.itunes.apple.com/verifyReceipt';
        }

        $postData = json_encode(
            array('receipt-data' => $receipt)
        );

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno != 0) {
            return $this->createErrorResponse('error', '充值失败！'.$errno);
        }

        $data = json_decode($response, true);
        if (empty($data)) {
            return $this->createErrorResponse('error', '充值验证失败');
        }

        if ($data['status'] == 21007) {
            //sandbox receipt
            return $this->requestReceiptData($userId, $amount, $receipt, $transactionId, true);
        }

        if (!isset($data['status']) || $data['status'] != 0) {
            return $this->createErrorResponse('error', '充值失败！状态码 :'.$data['status']);
        }

        if ($data['status'] == 0) {
            if (isset($data['receipt']) && !empty($data['receipt']['in_app'])) {
                $inApp = false;

                if ($transactionId) {
                    foreach ($data['receipt']['in_app'] as $value) {
                        if (ArrayToolkit::requireds($value, array('transaction_id', 'quantity', 'product_id')) && $value['transaction_id'] == $transactionId) {
                            $inApp = $value;
                            break;
                        }
                    }
                } else {
                    //兼容没有transactionId的模式
                    $inApp = $data['receipt']['in_app'][0];
                }

                if (!$inApp) {
                    return $this->createErrorResponse('error', 'receipt校验失败：找不到对应的transaction_id');
                }

                $token = 'iap-'.$inApp['transaction_id'];
                $quantity = $inApp['quantity'];
                $productId = $inApp['product_id'];

                try {
                    $calculatedAmount = $this->calculateBoughtAmount($productId, $quantity);

                    // if ($calculatedAmount != $amount) {
                    //     throw new \RuntimeException("金额校验错误，充值失败");
                    // }

                    $status = $this->buyCoinByIAP($userId, $calculatedAmount, 'none', $token);
                } catch (\Exception $e) {
                    return $this->createErrorResponse('error', $e->getMessage());
                }

                return array(
                    'status' => $status,
                );
            }
        }

        return array(
            'status' => false,
        );
    }

    private function calculateBoughtAmount($productId, $quantity)
    {
        $registeredProducts = $this->getSettingService()->get('mobile_iap_product', array());
        if (empty($registeredProducts[$productId])) {
            throw new \RuntimeException('该商品信息未与苹果服务器同步，充值失败');
        }

        return $registeredProducts[$productId]['price'] * $quantity;
    }

    private function coinPayNotify($payType, $amount, $sn, $status)
    {
        if (empty($sn) || empty($status)) {
            return $this->createErrorResponse('error', '订单数据缺失，充值失败！');
        }

        if ($payType == 'iap') {
            $payData = array(
                'amount' => $amount,
                'sn' => $sn,
                'status' => $status,
                'paidTime' => 0,
            );
            try {
                list($success, $order) = $this->getCashOrdersService()->payOrder($payData);

                return $success;
            } catch (\Exception $e) {
                return $this->createErrorResponse('error', $e->getMessage());
            }
        }

        return false;
    }

    private function buyCoinByIAP($userId, $amount, $payment, $token)
    {
        $formData['payment'] = $payment;
        $formData['userId'] = $userId;
        $formData['amount'] = $amount;
        $formData['token'] = $token;

        if ($this->getCashOrdersService()->getOrderByToken($token)) {
            throw new \RuntimeException('订单重复，充值无效');
        }

        $order = $this->getCashOrdersService()->addOrder($formData);

        if (empty($order)) {
            return $this->createErrorResponse('error', '充值失败！');
        }

        $this->coinPayNotify('iap', $amount, $order['sn'], 'success');

        return $order;
    }

    //payType is enum (none, alipay)
    public function del_buyCoin()
    {
        $user = $this->controller->getUserByToken($this->request);

        if (empty($user)) {
            return $this->createErrorResponse('not_login', '您尚未登录！');
        }

        $payType = $this->getParam('payType');
        $amount = $this->getParam('amount', 0);

        $formData['payment'] = $payType;
        $formData['userId'] = $user->id;
        $formData['amount'] = $amount;

        $order = $this->getCashOrdersService()->addOrder($formData);

        if (empty($order)) {
            return $this->createErrorResponse('error', '充值失败！');
        }

        return $order;
    }

    private function checkUserSetPayPassword($user, $newPayPassword)
    {
        $hasPayPassword = strlen($user['payPassword']) > 0;

        if ($hasPayPassword) {
            return;
        }

        $userPass = $user['nickname'];
        $this->controller->getAuthService()->changePayPassword($user['id'], $userPass, $newPayPassword);
    }

    private function initPayFieldsByTargetType($user, $targetType, $targetId)
    {
        $fields = $this->request->request->all();

        if ('vip' == $targetType) {
            $payVip = $this->controller->getLevelService()->getLevel($targetId);

            if (!$payVip) {
                return $this->createErrorResponse('error', '购买的vip类型不存在!');
            }

            $vip = $this->controller->getVipService()->getMemberByUserId($user['id']);

            if ($vip) {
                $currentVipLevel = $this->controller->getLevelService()->getLevel($vip['levelId']);

                if ($payVip['seq'] >= $currentVipLevel['seq']) {
                    $fields['buyType'] = 'upgrade';
                } else {
                    return $this->createErrorResponse('error', '会员类型不能降级付费!');
                }

                $fields['buyType'] = 'renew';
            } else {
                $fields['buyType'] = 'new';
            }
        }

        return $fields;
    }

    public function createOrder()
    {
        $targetType = $this->getParam('targetType');
        $targetId = $this->getParam('targetId');

        $user = $this->controller->getUserByToken($this->request);

        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '用户未登录，购买失败！');
        }

        try {
            /** @var $newApiResourceKernel ResourceKernel */
            $newApiResourceKernel = $this->controller->get('api_resource_kernel');

            $coinAmount = $this->getUseCoinAmount();
            $apiRequest = new ApiRequest(
                '/api/orders',
                'POST',
                array(),
                array(
                    'targetType' => $targetType,
                    'targetId' => $targetId,
                    'unencryptedPayPassword' => $this->getParam('payPassword'),
                    'couponCode' => $this->getParam('couponCode', ''),
                    'unit' => $this->getParam('unitType', ''),
                    'num' => $this->getParam('duration', 1),
                    'coinAmount' => $coinAmount
                ),
                array()
            );

            $this->controller->getContainer()->get('api_firewall')->handle($this->request);
            $result = $newApiResourceKernel->handleApiRequest($apiRequest);
            $trade = $this->getPayService()->getTradeByTradeSn($result['sn']);
            if ($trade['status'] === 'paid') {
                return array('status' => 'ok', 'paid' => true, 'message' => '', 'payUrl' => '');
            } else {
                $platformCreatedResult = $this->getPayService()->getCreateTradeResultByTradeSnFromPlatform($result['sn']);
                return array('status' => 'ok', 'paid' => false, 'message' => '', 'payUrl' => $platformCreatedResult['url']);
            }
        } catch (\Exception $exception) {
            return $this->createErrorResponse('error', $this->controller->get('translator')->trans($exception->getMessage()));
        }

    }

    /**
     * @return PayService
     */
    private function getPayService()
    {
        return $this->controller->getService('Pay:PayService');
    }

    private function getUseCoinAmount()
    {
        $payment = $this->getParam('payment', 'alipay');
        $priceType = 'RMB';
        $coinSetting = $this->controller->setting('coin');
        $coinEnabled = isset($coinSetting['coin_enabled']) && $coinSetting['coin_enabled'];

        if ($coinEnabled && isset($coinSetting['price_type'])) {
            $priceType = $coinSetting['price_type'];
        }

        if ($payment == 'coin' && !$coinEnabled) {
            return $this->createErrorResponse('coin_close', '网校关闭了课程购买！');
        }

        $cashRate = 1;

        if ($coinEnabled && isset($coinSetting['cash_rate'])) {
            $cashRate = $coinSetting['cash_rate'];
        }

        $totalPrice = $this->getParam('totalPrice', 0);
        $coinPayAmount = 0;
        if ($payment == 'coin') {
            $coinPayAmount = round($totalPrice * $cashRate, 2);
        }

        return $coinPayAmount;
    }

    public function createOrderBack()
    {
        $targetType = $this->getParam('targetType');
        $targetId = $this->getParam('targetId');
        $payment = $this->getParam('payment', 'alipay');
        $fields = $this->request->request->all();

        if (empty($targetType) || empty($targetId) || !in_array($targetType, array('course', 'vip', 'classroom'))) {
            return $this->createErrorResponse('error', '参数不正确');
        }

        $user = $this->controller->getUserByToken($this->request);

        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '用户未登录，购买失败！');
        }

        $priceType = 'RMB';
        $coinSetting = $this->controller->setting('coin');
        $coinEnabled = isset($coinSetting['coin_enabled']) && $coinSetting['coin_enabled'];

        if ($coinEnabled && isset($coinSetting['price_type'])) {
            $priceType = $coinSetting['price_type'];
        }

        $cashRate = 1;

        if ($coinEnabled && isset($coinSetting['cash_rate'])) {
            $cashRate = $coinSetting['cash_rate'];
        }

        $fields = $this->initPayFieldsByTargetType($user, $targetType, $targetId);

        if (isset($fields['error'])) {
            return $this->createErrorResponse($fields['error']['name'], $fields['error']['message']);
        }

        if ($payment == 'coin') {
            try {
                $this->checkUserSetPayPassword($user, $fields['payPassword']);
            } catch (\Exception $e) {
                //return $this->createErrorResponse('error', "修改失败, 请在pc端修改支付密码!");
            }

            $fields['coinPayAmount'] = (float) $fields['totalPrice'] * (float) $cashRate;
        }

        if ($payment == 'coin' && !$coinEnabled) {
            return $this->createErrorResponse('coin_close', '网校关闭了课程购买！');
        }

        $processor = OrderProcessorFactory::create($targetType);

        try {
            if (!isset($fields['couponCode'])) {
                $fields['couponCode'] = '';
            }

            list($amount, $totalPrice, $couponResult) = $processor->shouldPayAmount($targetId, $priceType, $cashRate, $coinEnabled, $fields);

            if ($payment == 'coin' && !$this->isCanPayByCoin($totalPrice, $user['id'], $cashRate)) {
                return $this->createErrorResponse('coin_no_enough', '账户余额不足！');
            }

            $amount = (string) ((float) $amount);

            if (isset($couponResult['useable']) && $couponResult['useable'] == 'yes') {
                $coupon = $fields['couponCode'];
                $couponDiscount = $couponResult['decreaseAmount'];
            }

            $orderFileds = array(
                'priceType' => $priceType,
                'totalPrice' => $totalPrice,
                'amount' => $amount,
                'coinRate' => $cashRate,
                'coinAmount' => empty($fields['coinPayAmount']) ? 0 : $fields['coinPayAmount'],
                'userId' => $user['id'],
                'payment' => empty($fields['payment']) ? 'alipay' : $fields['payment'],
                'targetId' => $targetId,
                'coupon' => empty($coupon) ? '' : $coupon,
                'couponDiscount' => empty($couponDiscount) ? 0 : $couponDiscount,
            );

            $order = $processor->createOrder($orderFileds, $fields);

            if ($order['amount'] == 0 && $order['coinAmount'] == 0) {
                $payData = array(
                    'sn' => $order['sn'],
                    'status' => 'success',
                    'amount' => $order['amount'],
                    'paidTime' => time(),
                );
                list($success, $order) = $this->getPayCenterService()->processOrder($payData);
            } elseif ($order['amount'] == 0 && $order['coinAmount'] > 0) {
                $payData = array(
                    'sn' => $order['sn'],
                    'status' => 'success',
                    'amount' => $order['amount'],
                    'paidTime' => time(),
                    'payment' => 'coin',
                );
                list($success, $order) = $this->getPayCenterService()->pay($payData);
                $processor = OrderProcessorFactory::create($order['targetType']);
            }

            if ($order['status'] == 'paid') {
                return array('status' => 'ok', 'paid' => true, 'message' => '', 'payUrl' => '');
            }

            return $this->payByAlipay($order, $this->controller->getToken($this->request));
        } catch (\Exception $e) {
            return $this->createErrorResponse('error', $e->getMessage());
        }
    }

    public function del_payClassRoom()
    {
        $targetType = $this->getParam('targetType');
        $targetId = $this->getParam('targetId');
        $payment = $this->getParam('payment', 'alipay');
        $fields = $this->request->request->all();

        if (empty($targetType) || empty($targetId) || !in_array($targetType, array('course', 'vip', 'classroom'))) {
            return $this->createErrorResponse('error', '参数不正确');
        }

        $user = $this->controller->getUserByToken($this->request);

        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '用户未登录，购买失败！');
        }

        $priceType = 'RMB';
        $coinSetting = $this->controller->setting('coin');
        $coinEnabled = isset($coinSetting['coin_enabled']) && $coinSetting['coin_enabled'];

        if ($coinEnabled && isset($coinSetting['price_type'])) {
            $priceType = $coinSetting['price_type'];
        }

        $cashRate = 1;

        if ($coinEnabled && isset($coinSetting['cash_rate'])) {
            $cashRate = $coinSetting['cash_rate'];
        }

        if ($payment == 'coin' && !$coinEnabled) {
            return $this->createErrorResponse('coin_close', '网校关闭了课程购买！');
        }

        $processor = OrderProcessorFactory::create($targetType);

        try {
            if (!isset($fields['couponCode'])) {
                $fields['couponCode'] = '';
            }

            list($amount, $totalPrice, $couponResult) = $processor->shouldPayAmount($targetId, $priceType, $cashRate, $coinEnabled, $fields);

            if ($payment == 'coin' && !$this->isCanPayByCoin($totalPrice, $user['id'], $cashRate)) {
                return $this->createErrorResponse('coin_no_enough', '账户余额不足！');
            }

            $amount = (string) ((float) $amount);

            if (isset($couponResult['useable']) && $couponResult['useable'] == 'yes') {
                $coupon = $fields['couponCode'];
                $couponDiscount = $couponResult['decreaseAmount'];
            }

            $orderFileds = array(
                'priceType' => $priceType,
                'totalPrice' => $totalPrice,
                'amount' => $amount,
                'coinRate' => $cashRate,
                'coinAmount' => empty($fields['coinPayAmount']) ? 0 : $fields['coinPayAmount'],
                'userId' => $user['id'],
                'payment' => empty($fields['payment']) ? 'alipay' : $fields['payment'],
                'targetId' => $targetId,
                'coupon' => empty($coupon) ? '' : $coupon,
                'couponDiscount' => empty($couponDiscount) ? 0 : $couponDiscount,
            );

            $order = $processor->createOrder($orderFileds, $fields);

            if ($order['amount'] == 0 && $order['coinAmount'] == 0) {
                $payData = array(
                    'sn' => $order['sn'],
                    'status' => 'success',
                    'amount' => $order['amount'],
                    'paidTime' => time(),
                );
                list($success, $order) = $this->getPayCenterService()->processOrder($payData);
            } elseif ($order['amount'] == 0 && $order['coinAmount'] > 0) {
                $payData = array(
                    'sn' => $order['sn'],
                    'status' => 'success',
                    'amount' => $order['amount'],
                    'paidTime' => time(),
                    'payment' => 'coin',
                );
                list($success, $order) = $this->getPayCenterService()->pay($payData);
                $processor = OrderProcessorFactory::create($order['targetType']);
            }

            if ($order['status'] == 'paid') {
                return array('status' => 'ok', 'paid' => true, 'message' => '', 'payUrl' => '');
            }

            return $this->payByAlipay($order, $this->controller->getToken($this->request));
        } catch (\Exception $e) {
            return $this->createErrorResponse('error', $e->getMessage());
        }
    }

    private function isCanPayByCoin($totalPrice, $userId, $cashRate)
    {
        $cash = $this->getUserCoin($userId, $cashRate);

        return ($cash * 100) >= ($totalPrice * 100);
    }

    private function getUserCoin($userId, $cashRate)
    {
        $account = $this->getCashAccountService()->getAccountByUserId($userId, true);

        if (empty($account)) {
            $account = $this->getCashAccountService()->createAccount($userId);
        }

        $cash = (float) $account['cash'] / (float) $cashRate;

        return $cash;
    }

    private function del_payVip()
    {
        $targetId = $this->getParam('targetId');

        if (empty($targetId)) {
            return $this->createErrorResponse('error', '创建订单数据失败!');
        }

        $token = $this->controller->getUserToken($this->request);
        $user = $this->controller->getUser();

        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '用户未登录，购买失败！');
        }

        $payVip = $this->controller->getLevelService()->getLevel($targetId);

        if (!$payVip) {
            return $this->createErrorResponse('error', '购买的vip类型不存在!');
        }

        $fields = $this->request->query->all();
        $vip = $this->controller->getVipService()->getMemberByUserId($user['id']);

        if ($vip) {
            $currentVipLevel = $this->controller->getLevelService()->getLevel($vip['levelId']);

            if ($payVip['seq'] > $currentVipLevel['seq']) {
                $fields['buyType'] = 'upgrade';
            } else {
                return $this->createErrorResponse('error', '会员类型不能降级付费!');
            }

            $fields['buyType'] = 'renew';
        } else {
            $fields['buyType'] = 'new';
        }

        $fields['targetType'] = 'vip';
        $targetType = 'vip';
        $priceType = 'RMB';
        $coinSetting = $this->controller->setting('coin');
        $coinEnabled = isset($coinSetting['coin_enabled']) && $coinSetting['coin_enabled'];

        if ($coinEnabled && isset($coinSetting['price_type'])) {
            $priceType = $coinSetting['price_type'];
        }

        $cashRate = 1;

        if ($coinEnabled && isset($coinSetting['cash_rate'])) {
            $cashRate = $coinSetting['cash_rate'];
        }

        if (!isset($fields['couponCode'])) {
            $fields['couponCode'] = '';
        }

        $processor = OrderProcessorFactory::create($targetType);
        list($amount, $totalPrice, $couponResult) = $processor->shouldPayAmount($targetId, $priceType, $cashRate, $coinEnabled, $fields);

        $fields['totalPrice'] = $totalPrice;
        $orderFileds = array(
            'priceType' => 'RMB',
            'totalPrice' => $totalPrice,
            'amount' => $amount,
            'coinAmount' => 0,
            'coinRate' => $cashRate,
            'userId' => $user['id'],
            'payment' => 'alipay',
            'targetId' => $targetId,
            'coupon' => empty($coupon) ? '' : $coupon,
            'couponDiscount' => empty($couponDiscount) ? 0 : $couponDiscount,
        );
        $order = $processor->createOrder($orderFileds, $fields);

        if ($order['status'] == 'paid') {
            return array('status' => 'ok', 'paid' => true, 'message' => '', 'payUrl' => '');
        }

        return $this->payByAlipay($order, $token['token']);
    }

    /**
     * payType iap, online.
     */
    private function del_payCourse()
    {
        $payType = $this->getParam('payType', 'online');
        $courseId = $this->getParam('courseId');

        if (empty($courseId)) {
            return $this->createErrorResponse('not_courseId', '没有找到加入的课程信息！');
        }

        $token = $this->controller->getUserToken($this->request);
        $user = $this->controller->getUser();

        if (!$user->isLogin()) {
            return $this->createErrorResponse('not_login', '用户未登录，加入学习失败！');
        }

        $this->formData['courseId'] = $courseId;
        $coinRate = $this->controller->setting('coin.cash_rate');

        if (!isset($coinRate)) {
            $coinRate = 1;
        }

        $course = $this->controller->getCourseService()->getCourse($courseId);
        $order = array();
        $order['targetId'] = $courseId;
        $order['targetType'] = 'course';
        $order['payment'] = 'alipay';
        $order['amount'] = $course['price'];
        $order['priceType'] = 'RMB';
        $order['totalPrice'] = $course['price'];
        $order['coinRate'] = $coinRate;
        $order['coinAmount'] = 0;

        if ($payType == 'iap') {
            return $this->payCourseByIAP($order, $user->id);
        }

        try {
            $order = $this->controller->getCourseOrderService()->createOrder($order);
        } catch (\Exception $e) {
            return $this->createErrorResponse('error', $e->getMessage());
        }

        if ($order['status'] == 'paid') {
            return array('status' => 'ok', 'paid' => true, 'message' => '', 'payUrl' => '');
        }

        return $this->payByAlipay($order, $token['token']);
    }

    private function payCourseByIAP($order, $userId)
    {
        $result = array('status' => 'error', 'message' => '', 'paid' => false, 'payUrl' => '');
        $coinType = $this->getSettingService()->get('coin', array());
        $coinEnabled = $coinType['coin_enabled'];

        if (empty($coinEnabled) || $coinEnabled == 0) {
            $result['message'] = '网校虚拟币未开启！';

            return $result;
        }

        $account = $this->getCashAccountService()->getAccountByUserId($userId, true);

        if (empty($account)) {
            $account = $this->getCashAccountService()->createAccount($userId);
        }

        $cash = (float) $account['cash'] / (float) $coinType['cash_rate'];
        $price = (float) $order['totalPrice'];

        if ($cash < $price) {
            $result['message'] = '账户余额不够';

            return $result;
        }

        $order['coinAmount'] = (string) ((float) $price * (float) $coinType['cash_rate']);
        $order['priceType'] = $coinType['price_type'];
        $order['amount'] = 0;
        $order['userId'] = $userId;
        $order['coinRate'] = $coinType['cash_rate'];

        $order = $this->controller->getCourseOrderService()->createOrder($order);
        list($success, $order) = $this->processorOrder($order);

        if ($success && $order['status'] == 'paid') {
            $result['status'] = 'ok';
            $result['paid'] = true;
        } else {
            $result['message'] = '支付失败!';
        }

        return $result;
    }

    private function processorOrder($order)
    {
        $success = false;

        if ($order['amount'] == 0 && $order['coinAmount'] == 0) {
            $payData = array(
                'sn' => $order['sn'],
                'status' => 'success',
                'amount' => $order['amount'],
                'paidTime' => time(),
            );
            list($success, $order) = $this->getPayCenterService()->processOrder($payData);
        } elseif ($order['amount'] == 0 && $order['coinAmount'] > 0) {
            $payData = array(
                'sn' => $order['sn'],
                'status' => 'success',
                'amount' => $order['amount'],
                'paidTime' => time(),
                'payment' => 'coin',
            );
            list($success, $order) = $this->getPayCenterService()->pay($payData);
        }

        return array($success, $order);
    }

    private function payByAlipay($order, $token)
    {
        $result = array('status' => 'error', 'message' => '', 'paid' => false, 'payUrl' => '');
        $payment = $this->controller->setting('payment', array());

        if (empty($payment['enabled'])) {
            $result['message'] = '支付功能未开启！';

            return $result;
        }

        if (empty($payment['alipay_enabled'])) {
            $result['message'] = '支付功能未开启！';

            return $result;
        }

        if (empty($payment['alipay_key']) || empty($payment['alipay_secret']) || empty($payment['alipay_account'])) {
            $result['message'] = '支付宝参数不正确！';

            return $result;
        }

        if (empty($payment['alipay_type']) || $payment['alipay_type'] != 'direct') {
            $payUrl = $this->controller->generateUrl('mapi_order_submit_pay_request', array('id' => $order['id'], 'token' => $token), true);
            $result['payUrl'] = $payUrl;
        } else {
            $result['payUrl'] = MobileAlipayConfig::createAlipayOrderUrl($this->request, 'edusoho', $order);
        }

        $result['status'] = 'ok';

        return $result;
    }

    private function getClassroomService()
    {
        return $this->controller->getService('Classroom:ClassroomService');
    }

    private function getLevelService()
    {
        return $this->controller->getService('VipPlugin:Vip:LevelService');
    }

    protected function getCourseSetService()
    {
        return $this->controller->getService('Course:CourseSetService');
    }
}
