<?php


class RBKmoney_Payform_Model_Paymentmethod extends Mage_Payment_Model_Method_Abstract {

    protected $_code  = 'payform';
    protected $_formBlockType = 'payform/form_payform';
    protected $_infoBlockType = 'payform/info_payform';

    public function assignData($data)
    {
        $info = $this->getInfoInstance();

        return $this;
    }

    public function validate()
    {
        parent::validate();

        return $this;
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('payform/payment/redirect', array('_secure' => false));
    }

}
