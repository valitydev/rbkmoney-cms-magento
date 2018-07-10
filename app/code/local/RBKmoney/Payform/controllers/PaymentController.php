<?php


class RBKmoney_Payform_PaymentController extends Mage_Core_Controller_Front_Action
{

    /**
     * Constants for Callback
     */
    const SIGNATURE = 'HTTP_CONTENT_SIGNATURE';
    const SIGNATURE_PATTERN = "/alg=(\S+);\sdigest=/";

    const EVENT_TYPE = 'eventType';

    const INVOICE = 'invoice';
    const INVOICE_ID = 'id';
    const INVOICE_SHOP_ID = 'shopID';
    const INVOICE_METADATA = 'metadata';
    const INVOICE_STATUS = 'status';
    const INVOICE_AMOUNT = 'amount';

    const ORDER_ID = 'order_id';

    /**
     * Openssl verify
     */
    const OPENSSL_VERIFY_SIGNATURE_IS_CORRECT = 1;

    /**
     * e.g. http{s}://{your-site}/rbkmoney/payment/redirect
     */
    public function redirectAction()
    {
        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'payform', array('template' => 'payform/redirect.phtml'));
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    /**
     * e.g. http{s}://{your-site}/rbkmoney/payment/notification
     */
    public function notificationAction()
    {
        $content = file_get_contents('php://input');

        $logs = array(
            'content' => $content,
            'method' => $_SERVER['REQUEST_METHOD'],
        );

        /** @var RBKmoney_Payform_Helper_Data $payform */
        $payform = Mage::helper("payform");

        if (empty($_SERVER[static::SIGNATURE])) {
            $message = 'Webhook notification signature missing';
            static::outputWithExit($message, $logs);
        }
        $logs['signature'] = $_SERVER[static::SIGNATURE];

        $signature = $this->getSignatureFromHeader($_SERVER[static::SIGNATURE]);
        $logs['signature_from_header'] = $signature;
        if (empty($signature)) {
            $message = 'Signature is missing';
            static::outputWithExit($message, $logs);
        }

        $signatureDecode = $this->urlSafeB64decode($signature);
        $publicKey = $payform->getCallbackPublicKey();
        if (!$this->verificationSignature($content, $signatureDecode, $publicKey)) {
            $message = 'Webhook notification signature mismatch';
            static::outputWithExit($message, $logs);
        }


        $required_fields = [static::INVOICE, static::EVENT_TYPE];
        $data = json_decode($content, TRUE);

        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $message = 'One or more required fields are missing';
                static::outputWithExit($message, $logs);
            }
        }

        $current_shop_id = $payform->getShopId();
        if ($data[static::INVOICE][static::INVOICE_SHOP_ID] != $current_shop_id) {
            $message = static::INVOICE_SHOP_ID . ' is missing';
            static::outputWithExit($message, $logs);
        }

        if (empty($data[static::INVOICE][static::INVOICE_METADATA][static::ORDER_ID])) {
            $message = static::ORDER_ID . ' is missing';
            static::outputWithExit($message, $logs);
        }

        $orderId = $data[static::INVOICE][static::INVOICE_METADATA][static::ORDER_ID];

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($orderId);

        if (empty($order)) {
            $message = 'Order ' . $orderId . ' is missing';
            static::outputWithExit($message, $logs);
        }

        $order_amount = (int)$payform->prepareAmount(number_format($order->getGrandTotal(), 2));
        $invoice_amount = (int)$data[static::INVOICE][static::INVOICE_AMOUNT];
        if ($order_amount != $invoice_amount) {
            $message = 'Received amount vs Order amount mismatch';
            static::outputWithExit($message, $logs);
        }

        if ($order->getState() == Mage_Sales_Model_Order::STATE_PROCESSING) {
            switch ($data[static::INVOICE][static::INVOICE_STATUS]) {
                case 'paid':
                    $order->setState(Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW, true, 'Payment Success.');
                    $order->getPayment()->setLastTransId($data[static::INVOICE][static::INVOICE_ID]);
                    $order->getPayment()->setAdditionalInformation($data);
                    $order->save();
                    static::outputWithExit('OK, paid', $logs, $payform::HTTP_CODE_OK);
                    break;
                case 'cancelled':
                    $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Payment Cancelled.');
                    $order->save();
                    static::outputWithExit('OK, cancelled', $logs, $payform::HTTP_CODE_OK);
                    break;
                default:
                    // nothing
            }
        }

        static::outputWithExit('OK', $logs, $payform::HTTP_CODE_OK);
    }

    private function urlSafeB64decode($string)
    {
        return base64_decode(strtr($string, '-_,', '+/='));
    }

    private function getSignatureFromHeader($content_signature)
    {
        return preg_replace(static::SIGNATURE_PATTERN, '', $content_signature);
    }

    /**
     * Verification signature
     *
     * @param string $data
     * @param string $signature
     * @param string $public_key
     *
     * @return bool
     */
    private function verificationSignature($data = '', $signature = '', $public_key = '')
    {
        if (empty($data) || empty($signature) || empty($public_key)) {
            return FALSE;
        }
        $public_key_id = openssl_get_publickey($public_key);
        if (empty($public_key_id)) {
            return FALSE;
        }
        $verify = openssl_verify($data, $signature, $public_key_id, OPENSSL_ALGO_SHA256);
        return ($verify == static::OPENSSL_VERIFY_SIGNATURE_IS_CORRECT);
    }

    private static function outputWithExit($message, $logs, $header = RBKmoney_Payform_Helper_Data::HTTP_CODE_BAD_REQUEST)
    {
        /** @var RBKmoney_Payform_Helper_Data $payform */
        $payform = Mage::helper("payform");

        $response = ['message' => $message];
        http_response_code($header);
        $payform->log($message, $logs);
        echo json_encode($response);
        exit();
    }

}
