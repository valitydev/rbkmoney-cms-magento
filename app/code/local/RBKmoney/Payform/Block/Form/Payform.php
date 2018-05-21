<?php

class RBKmoney_Payform_Block_Form_Payform extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('payform/form/payform.phtml');
    }
}
