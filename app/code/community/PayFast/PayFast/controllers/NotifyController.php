<?php
/**
 * NotifyController.php
 *
 * Copyright (c) 2008 PayFast (Pty) Ltd
 * You (being anyone who is not PayFast (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
 * 
 * @author     Jonathan Smit
 * @link       http://www.payfast.co.za/help/cube_cart
 * @category   PayFast
 * @package    PayFast_PayFast
 */

// Include the PayFast common file
define( 'PF_DEBUG', ( Mage::getStoreConfig( 'payment/payfast/debugging' ) ? true : false ) );
include_once( dirname( __FILE__ ) .'/../payfast_common.inc' );


/**
 * PayFast_PayFast_NotifyController
 */
class PayFast_PayFast_NotifyController extends Mage_Core_Controller_Front_Action
{
    // {{{ indexAction()
	/**
	 * indexAction
     * 
     * Instantiate ITN model and pass ITN request to it
	 */
    public function indexAction()
    {
        // Variable Initialization
        $pfError = false;
        $pfErrMsg = '';
        $pfData = array();
        $pfHost = ( ( Mage::getStoreConfig( 'payment/payfast/server' ) == 'live' ) ? 'www' : 'sandbox' ) . '.payfast.co.za';
        $pfOrderId = '';
        $pfParamString = '';
        
        pflog( 'PayFast ITN call received' );
        pflog( 'Server = '. Mage::getStoreConfig( 'payment/payfast/server' ) );
        
        //// Notify PayFast that information has been received
        if( !$pfError )
        {
            header( 'HTTP/1.0 200 OK' );
            flush();
        }
        
        //// Get data sent by PayFast
        if( !$pfError )
        {
            pflog( 'Get posted data' );
        
            // Posted variables from ITN
            $pfData = pfGetData();
        
            pflog( 'PayFast Data: '. print_r( $pfData, true ) );
        
            if( $pfData === false )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }
        
        //// Verify security signature
        if( !$pfError )
        {
            pflog( 'Verify security signature' );

            $passPhrase = Mage::getStoreConfig( 'payment/payfast/passphrase' );
            $pfPassphrase = empty( $passPhrase ) ? null : $passPhrase;

            // If signature different, log for debugging
            if( !pfValidSignature( $pfData, $pfParamString, $pfPassphrase ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
            }
        }
        
        //// Verify source IP (If not in debug mode)
        if( !$pfError && !defined( 'PF_DEBUG' ) )
        {
            pflog( 'Verify source IP' );
        
            if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
            }
        }
        
        //// Get internal order and verify it hasn't already been processed
        if( !$pfError )
        {
            pflog( "Check order hasn't been processed" );
            
            // Load order
    		$trnsOrdId = $pfData['m_payment_id'];
    		$order = Mage::getModel( 'sales/order' );
            $order->loadByIncrementId( $trnsOrdId );
    		$this->_storeID = $order->getStoreId();
            
            // Check order is in "pending payment" state
            if( $order->getStatus() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_ORDER_PROCESSED;
            }
        }
        
        //// Verify data received
        if( !$pfError )
        {
            pflog( 'Verify data received' );
        
            $pfValid = pfValidData( $pfHost, $pfParamString );
        
            if( !$pfValid )
            {
                $pfError = true;
                $pfErrMsg = PF_ERR_BAD_ACCESS;
            }
        }

        //// Check status and update order
        if( !$pfError )
        {
            pflog( 'Check status and update order' );
            
            // Successful
            if( $pfData['payment_status'] == "COMPLETE" )
            {
                pflog( 'Order complete' );
                
                // Update order additional payment information
                $payment = $order->getPayment(); 
        		$payment->setAdditionalInformation( "payment_status", $pfData['payment_status'] );
        		$payment->setAdditionalInformation( "m_payment_id", $pfData['m_payment_id'] );
                $payment->setAdditionalInformation( "pf_payment_id", $pfData['pf_payment_id'] );
                $payment->setAdditionalInformation( "email_address", $pfData['email_address'] );
        		$payment->setAdditionalInformation( "amount_fee", $pfData['amount_fee'] );
                $payment->save();

                // Save invoice
                $this->saveInvoice( $order );
            }
        }
        
        // If an error occurred
        if( $pfError )
        {
            pflog( 'Error occurred: '. $pfErrMsg );
            
            // TODO: Use Magento structures to send email
        }
    }
    // }}}
    // {{{ saveInvoice()
    /**
	 * saveInvoice
	 */
	protected function saveInvoice( Mage_Sales_Model_Order $order )
    {
        pflog( 'Saving invoice' );
        
		// Check for mail msg
		$invoice = $order->prepareInvoice();

		$invoice->register()->capture();
		Mage::getModel( 'core/resource_transaction' )
		   ->addObject( $invoice )
		   ->addObject( $invoice->getOrder() )
		   ->save();
		//$invoice->sendEmail();
		
		$message = Mage::helper( 'payfast' )->__( 'Notified customer about invoice #%s.', $invoice->getIncrementId() );
        $comment = $order->sendNewOrderEmail()->addStatusHistoryComment( $message )
              ->setIsCustomerNotified( true )
              ->save();
    }
    // }}}
}