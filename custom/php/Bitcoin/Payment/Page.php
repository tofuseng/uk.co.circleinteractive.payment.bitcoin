<?php 

/**
 * Page class for BitcoinD processor payment page
 * @author  andyw@circle
 * @package com.uk.andyw.payment.bitcoin
 */
class Bitcoin_Payment_Page extends CRM_Core_Page {

    /**
     * Page run - add resources, assign templates vars, then call parent run method
     */
    public function run() {
        
        $resources = CRM_Core_Resources::singleton();

        # add styles
        $resources->addStyleFile(
            bitcoin_extension_name(), 
            'custom/css/bitcoin-payment.css',
            CRM_Core_Resources::DEFAULT_WEIGHT,
            'html-header'
        );

        # add javascript
        $resources->addScriptFile(bitcoin_extension_name(), 'custom/js/bitcoin-payment.js');

        $transaction = &$_SESSION['bitcoin_trxn'];

        $this->assign('qr_code',      Bitcoin_Utils_QRCode::getInline($transaction->pay_address));
        $this->assign('pay_address',  $transaction->pay_address);
        $this->assign('thankyou_url', $transaction->thankyou_url);
        $this->assign('amount',       $transaction->amount);
           
        return parent::run();
    
    }

};