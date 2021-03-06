<?php

/**
 * IPN handler for BitPay payment processor
 * Also processes invoice updates queried from BitPay via cron when SSL not available
 * @author  andyw@circle
 * @package uk.co.circleinteractive.payment.bitcoin
 */
class BitPay_Payment_IPN extends CRM_Core_Payment_BaseIPN {

    public static $_paymentProcessor = null;
    protected     $component;
    
    public function __construct() {
        parent::__construct();
    }

    /**
     * Main callback notification handler
     * @param string $module  'contribute' or 'event'
     */
    public function main($module, $invoice) {
        
        CRM_Core_Error::debug_log_message('BitPay IPN: module = ' . $module . ', data = ' . print_r($invoice, true));

        $bitpay_id = isset($invoice['id']) ? $invoice['id'] : $invoice['bitpay_id'];

        if ($stored_transaction = BitPay_Payment_BAO_Transaction::load(array(
            'bitpay_id' => $bitpay_id
        ))) {
            
            # get contribution id
            $contribution_id = $stored_transaction['contribution_id'];

            # write updated status back to database
            BitPay_Payment_BAO_Transaction::save($invoice + array(
                'contribution_id' => $contribution_id,
                'bitpay_id'       => $bitpay_id
            ));

            # if anything other than complete, return for now
            # todo: may want to handle expired, invalid
            if ($invoice['status'] != 'complete') 
                return;

            # ok, get started then ..
            $objects = array();
            $ids     = array();

            $this->component    = $module;
            $input['component'] = $module; 
            $input['amount']    = $invoice['price'];

            if (!isset($invoice['posData']['c']) or empty($invoice['posData']['c']))
                return CRM_Core_Error::debug_log_message(
                    "BitPay: Unable to complete payment - missing or empty contact id in notification handler"
                );

            $ids['contact']      = $invoice['posData']['c'];
            $ids['contribution'] = $contribution_id;
            $ids['invoice']      = $bitpay_id;

            switch ($module) {
                
                case 'event':
                    
                    # get participant and event ids
                    $dao = CRM_Core_DAO::executeQuery("
                           SELECT pp.participant_id, p.event_id FROM civicrm_participant_payment pp
                       INNER JOIN civicrm_participant p ON p.id = pp.participant_id
                            WHERE pp.contribution_id = %1
                    ", array(
                          1 => array($contribution_id, 'Positive')
                       )
                    );

                    if (!$dao->fetch())
                        return CRM_Core_Error::debug_log_message(
                            "BitPay: Unable to complete payment - could not find participant payment record."
                        );
                    
                    $ids['event']       = $dao->event_id;
                    $ids['participant'] = $dao->participant_id;

                    break;
                
                case 'contribute':
                    
                    # get membership id, if applicable
                    $ids['membership'] = CRM_Core_DAO::singleValueQuery("
                        SELECT membership_id FROM civicrm_membership_payment
                         WHERE contribution_id = %1
                    ", array(
                          1 => array($contribution_id, 'Positive')
                       )
                    );

                    $ids['related_contact']     = isset($invoice['posData']['r']) ? $invoice['posData']['r'] : false;
                    $ids['onbehalf_dupe_alert'] = isset($invoice['posData']['d']) ? $invoice['posData']['d'] : false;

                    break;
            }

            if (!$this->validateData($input, $ids, $objects)) 
                return CRM_Core_Error::debug_log_message(ts("Transaction failed: Unable to validate data in %1::%2, %3", array(
                    1 => __CLASS__,
                    2 => __METHOD__,
                    3 => __LINE__
                )));

            self::$_paymentProcessor = &$objects['paymentProcessor'];
            
            # complete the transaction

            # may as well tell me about it in the process too, as I'd be interested to know
            CRM_Core_Error::debug_log_message('Transaction success: ' . $module);
            return $this->single($input, $ids, $objects, $invoice);

        } else {
            return CRM_Core_Error::debug_log_message(ts("BitPay: failed to load invoice id %1 in %2::%3", array(
                1 => $invoice['id'],
                2 => __CLASS__,
                3 => __METHOD__
            )));
        }

    }

    public function single(&$input, &$ids, &$objects, &$invoice) {
        
        $contribution =& $objects['contribution'];
        
        # make sure the invoice is valid and matches what we have in the contribution record
        if ($contribution->invoice_id != $ids['invoice']) {
            CRM_Core_Error::debug_log_message("Invoice values dont match between database and IPN request");
            CRM_Core_Error::debug_log_message("contribution->invoice_id=" . $contribution->invoice_id);
            return false;
        }


        if ($contribution->total_amount != $invoice['price'])
            return (bool)CRM_Core_Error::debug_log_message(
                "Amount values dont match between database and IPN request"
            );

        $transaction = new CRM_Core_Transaction();
        
        $participant = &$objects['participant'];
        $membership  = &$objects['membership'];
        $status      = $invoice['status'];
        
        switch ($status) {

            # check for failed statuses and fail transaction if present
            case 'expired':
            case 'invalid':
                return $this->failed($objects, $transaction);

            # completed transactions
            case 'complete':   
                
                # set these vars to prevent warnings in completeTransaction()
                $input['is_test'] = $contribution->is_test;
                $input['trxn_id'] = $contribution->trxn_id;

                # complete transaction
                CRM_Core_Error::debug_log_message("BitPay: Invoice status complete. Completing transaction ...");             
                $this->completeTransaction($input, $ids, $objects, $transaction, false);
                CRM_Core_Error::debug_log_message("BitPay: Transaction complete.");             
                
                return true;

            default:
                return (bool)CRM_Core_Error::debug_log_message(
                    "Unhandled status: " . $status
                );

        }
        
    }

    /**
     * Verify the callback notification was a good callback notification
     * Otherwise, be all like rawwwr and stuff
     * @return mixed  array if successful, string if error
     */
    public function verifyNotification() {

        if (!isset($_GET['processor_id']) or empty($_GET['processor_id']))
            CRM_Core_Error::fatal(ts('processor_id param empty or missing in %1::%2', array(
                1 => __CLASS__,
                2 => __METHOD__
            )));

        # get api key for processor
        try {

            $api_key = civicrm_api3('paymentProcessor', 'getvalue', array(
                'id'     => $_GET['processor_id'],
                'return' => 'user_name'
            ));

        } catch (CiviCRM_API3_Exception $e) {
            CRM_Core_Error::fatal(ts('Unable to get api key for processor id %1', array(
                1 => $processor_id
            )));
        }

        require_once "packages/bitpay/php-client/bp_lib.php";    
        return bpVerifyNotification($api_key);
    
    }

}