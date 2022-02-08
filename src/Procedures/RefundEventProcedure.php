<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
 
namespace Novalnet\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Novalnet\Services\PaymentService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Order\Models\OrderType;

/**
 * Class RefundEventProcedure
 */
class RefundEventProcedure
{
    use Loggable;
    
    /**
     *
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     *
     * @var PaymentService
     */
    private $paymentService;
    
    /**
     * @var transaction
     */
    private $transaction;
    
    /**
     * Constructor.
     *
     * @param PaymentHelper $paymentHelper
     * @param PaymentService $paymentService
     */
     
    public function __construct( PaymentHelper $paymentHelper, TransactionService $tranactionService,
                                 PaymentService $paymentService)
    {
        $this->paymentHelper   = $paymentHelper;
        $this->paymentService  = $paymentService;
        $this->transaction     = $tranactionService;
    }   
    
    /**
     * @param EventProceduresTriggered $eventTriggered
     * 
     */
    public function run(
        EventProceduresTriggered $eventTriggered
    ) {
        /* @var $order Order */
     
       $order = $eventTriggered->getOrder(); 
       $parent_order_id = $order->id;
       
     $this->getLogger(__METHOD__)->error('order obj', $order);
     
        // Checking order type
       if ($order->typeId == OrderType::TYPE_CREDIT_NOTE) {
        foreach ($order->orderReferences as $orderReference) {
            $parent_order_id = $orderReference->originOrderId;
            $child_order_id = $orderReference->orderId;
        }
       } 
        
        $payments = pluginApp(\Plenty\Modules\Payment\Contracts\PaymentRepositoryContract::class);  
       $paymentDetails = $payments->getPaymentsByOrderId($parent_order_id);
        
       $orderAmount = (float) $order->amounts[0]->invoiceTotal;
       foreach ($paymentDetails as $paymentDetail) {
            $parent_order_amount = (float) $paymentDetail->amount;
        } 
        $this->getLogger(__METHOD__)->error('payment details', $paymentDetails);
       
       $paymentKey = $paymentDetails[0]->method->paymentKey;
       $key = $this->paymentService->getkeyByPaymentKey($paymentKey);
       $parentOrder = $this->transaction->getTransactionData('orderNo', $parent_order_id);
      $this->getLogger(__METHOD__)->error('order details', $parentOrder);
     
       foreach($parentOrder as $orderde) {
        $this->getLogger(__METHOD__)->error('order details loop', $orderde);
         $additionalInfo = json_decode($orderde->additionalInfo, true);
        $this->getLogger(__METHOD__)->error('additional', $additionalInfo);
          if(isset($additionalInfo['tid_status']) && $additionalInfo['tid_status'] == 100) {
              $status = $additionalInfo['tid_status'];
              $parentOrder[0]->tid = $orderde->tid;
              $parentOrder[0]->amount = $orderde->amount;
              break;
          }
          
       }
     
    
        $parent_order_amount = $parentOrder[0]->amount;
       //foreach ($paymentDetails[0]->properties as $paymentStatus)
        //{
            //if($paymentStatus->typeId == 30)
         //{
           // $status = $paymentStatus->value;
         //} 
      //}
        if ($status == 100)   
        {
         $this->getLogger(__METHOD__)->error('enter', $status);
            try {
                $paymentRequestData = [
                    'vendor'         => $this->paymentHelper->getNovalnetConfig('novalnet_vendor_id'),
                    'auth_code'      => $this->paymentHelper->getNovalnetConfig('novalnet_auth_code'),
                    'product'        => $this->paymentHelper->getNovalnetConfig('novalnet_product_id'),
                    'tariff'         => $this->paymentHelper->getNovalnetConfig('novalnet_tariff_id'),
                    'key'            => $key, 
                    'refund_request' => 1, 
                    'tid'            => $parentOrder[0]->tid, 
                     'refund_param'  => (float) $orderAmount * 100,
                    'remote_ip'      => $this->paymentHelper->getRemoteAddress(),
                    'lang'           => 'de'   
                     ];
                    
                $response = $this->paymentHelper->executeCurl($paymentRequestData, NovalnetConstants::PAYPORT_URL);
                $responseData =$this->paymentHelper->convertStringToArray($response['response'], '&');
                   $this->getLogger(__METHOD__)->error('response', $responseData);               
                if ($responseData['status'] == '100') {

                    $transactionComments = '';
                    if (!empty($responseData['tid'])) {
                        $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('refund_message_new_tid', $paymentRequestData['lang']), $parentOrder[0]->tid, sprintf('%0.2f', ($paymentRequestData['refund_param'] / 100)) , $responseData['tid']);
                     } else {
                        $transactionComments .= PHP_EOL . sprintf($this->paymentHelper->getTranslatedText('refund_message', $paymentRequestData['lang']), $parentOrder[0]->tid, sprintf('%0.2f', ($paymentRequestData['refund_param'] / 100)) );
                     }
                    
                    $paymentData['tid'] = !empty($responseData['tid']) ? $responseData['tid'] : $parentOrder[0]->tid;
                    $paymentData['tid_status'] = $responseData['tid_status'];
                    $paymentData['refunded_amount'] = (float) $orderAmount;
                    $paymentData['child_order_id'] = $child_order_id;
                    $paymentData['parent_order_id'] = $parent_order_id;
                    $paymentData['parent_tid'] = $parentOrder[0]->tid;
                    $paymentData['parent_order_amount'] = (float) $parent_order_amount;
                    $paymentData['payment_name'] = strtolower($paymentKey);
                    
                    if ($order->typeId == OrderType::TYPE_CREDIT_NOTE) {
                        $this->getLogger(__METHOD__)->error('credit note order', $paymentData);
                        $this->paymentHelper->createRefundPayment($paymentDetails, $paymentData, $transactionComments);
                    } else {
                        $this->getLogger(__METHOD__)->error('status change order', $paymentData);
                        $paymentData['tid']         = !empty($responseData['tid']) ? $responseData['tid'] : $parentOrder[0]->tid;
                        $this->paymentHelper->updatePayments($parentOrder[0]->tid, $responseData['tid_status'], $parent_order_id, true);
                        
                    }

                } else {
                    $error = $this->paymentHelper->getNovalnetStatusText($responseData);
                    $this->getLogger(__METHOD__)->error('Novalnet::doRefundError', $error);
                }
            } catch (\Exception $e) {
                        $this->getLogger(__METHOD__)->error('Novalnet::doRefund', $e);
                    }   
        } else {
           $this->getLogger(__METHOD__)->error('Novalnet::doRefund', 'TID status is not 100');
        }
    }

}
