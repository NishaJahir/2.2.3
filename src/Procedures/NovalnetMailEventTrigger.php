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
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;

/**
 * Class NovalnetMailEventTrigger
 */
class NovalnetMailEventTrigger
{
    use Loggable;
    
 use Loggable;
    
    /**
     *
     * @var PaymentService
     */
    private $paymentService;
    
    /**
     *
     * @var Transaction
     */
    private $transaction;
    
    /**
     * Constructor.
     *
     * @param PaymentService $paymentService
     * @param TransactionService $tranactionService
     */
     
    public function __construct(PaymentService $paymentService, TransactionService $tranactionService)
    {
        $this->paymentService  = $paymentService;
        $this->transaction     = $tranactionService;
    }   
    
    /**
     * @param EventProceduresTriggered $eventTriggered
     */
    public function run(
        EventProceduresTriggered $eventTriggered,
        EventProceduresService $eventService;
    ) {
        /* @var $order Order */
     
        $order = $eventTriggered->getOrder(); 
        $payments = pluginApp(\Plenty\Modules\Payment\Contracts\PaymentRepositoryContract::class);  
        $paymentDetails = $payments->getPaymentsByOrderId($order->id);
        
        if(count($paymentDetails) == 1)  {
           $this->getLogger(__METHOD__)->error('payment created', $paymentDetails);
           $eventService->fireTrigger($order->id, 'Novalnet', 'NovalnetMailEventTrigger');
        } else {
           $this->getLogger(__METHOD__)->error('payment not created', $paymentDetails);
        }
    }
}
