<?php
/**
 * Copyright (c) 2008 PayFast (Pty) Ltd
 * You (being anyone who is not PayFast (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
 */
namespace Payfast\Payfast\Controller;

include_once dirname(__FILE__) . '/../Model/payfast_common.inc';

use Magento\Checkout\Controller\Express\RedirectLoginInterface;
use Magento\Framework\App\Action\Action as AppAction;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\Generic;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\Quote;
use \Magento\Customer\Model\Session;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction;
use Payfast\Payfast\Model\Config;
use Payfast\Payfast\Model\Payfast;
use Psr\Log\LoggerInterface;

/**
 * Abstract Express Checkout Controller
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractPayfast extends AppAction implements RedirectLoginInterface, ActionInterface
{
    /**
     * Internal cache of checkout models
     *
     * @var array
     */
    protected $_checkoutTypes = [ ];

    /**
     * @var Config
     */
    protected $_config;

    /**
     * @var Quote
     */
    protected $_quote = false;

    /**
     * Config mode type
     *
     * @var string
     */
    protected $_configType = 'Payfast\Payfast\Model\Config';

    /** Config method type @var string */
    protected $_configMethod = Config::METHOD_CODE;

    /**
     * Checkout mode type
     *
     * @var string
     */
    protected $_checkoutType;

    /**
     * @var Session
     */
    protected $customerSession;

    /** @var \Magento\Checkout\Model\Session $checkoutSession */
    protected $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var Generic
     */
    protected $_payfastSession;

    /**
     * @var Data
     */
    protected $urlHelper;

    /**
     * @var Order $orderResourceModel
     */
    protected $orderResourceModel;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /** @var  \Magento\Sales\Model\Order $_order */
    protected $_order;

    /** @var PageFactory  */
    protected $_pageFactory;

    /** @var Transaction  $salesTransactionResourceModel*/
    protected $salesTransactionResourceModel;

    /**
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /** @var Payfast $paymentMethod*/
    protected $paymentMethod;

    protected $pageFactory;

    protected $orderSender;

    protected $invoiceSender;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Generic $payfastSession
     * @param Data $urlHelper
     * @param Order $orderResourceModel
     * @param LoggerInterface $logger
     * @param TransactionFactory $transactionFactory
     * @param Payfast $paymentMethod
     * @param OrderSender $orderSender
     * @param InvoiceSender $invoiceSender
     * @param Transaction $salesTransactionResourceModel
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        OrderFactory $orderFactory,
        Generic $payfastSession,
        Data $urlHelper,
        Order $orderResourceModel,
        LoggerInterface $logger,
        TransactionFactory $transactionFactory,
        Payfast $paymentMethod,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        Transaction $salesTransactionResourceModel
    ) {
        $pre = __METHOD__ . " : ";

        $this->_logger = $logger;

        $this->_logger->debug($pre . 'bof');

        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->payfastSession = $payfastSession;
        $this->urlHelper = $urlHelper;
        $this->orderResourceModel = $orderResourceModel;
        $this->pageFactory = $pageFactory;
        $this->transactionFactory = $transactionFactory;
        $this->paymentMethod = $paymentMethod;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->salesTransactionResourceModel = $salesTransactionResourceModel;

        parent::__construct($context);

        $parameters = [ 'params' => [ $this->_configMethod ] ];
        $this->_config = $this->_objectManager->create($this->_configType, $parameters);

        if (! defined('PF_DEBUG')) {
            define('PF_DEBUG', $this->getConfigData('debug'));
        }

        $this->_logger->debug($pre . 'eof');
    }

    /**
     * Custom getter for payment configuration
     *
     * @param string $field   i.e merchant_id, server
     *
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfigData($field)
    {
        return $this->_config->getValue($field);
    }

    /**
     * Instantiate
     *
     * @return void
     * @throws LocalizedException
     */
    protected function _initCheckout()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');

        $this->checkoutSession->loadCustomerQuote();

        $this->_order = $this->checkoutSession->getLastRealOrder();

        if (!$this->_order->getId()) {
            $phrase = __('We could not find "Order" for processing');
            $this->_logger->critical($pre . $phrase);

            $this->getResponse()->setStatusHeader(404, '1.1', 'Not found');
            throw new LocalizedException($phrase);
        }

        if ($this->_order->getState() != \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
            $this->_logger->debug($pre . 'updating order state and status');

            $this->_order->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
            $this->_order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

            $this->orderResourceModel->save($this->_order);
        }

        if ($this->_order->getQuoteId()) {
            $this->checkoutSession->setPayfastQuoteId($this->checkoutSession->getQuoteId());
            $this->checkoutSession->setPayfastSuccessQuoteId($this->checkoutSession->getLastSuccessQuoteId());
            $this->checkoutSession->setPayfastRealOrderId($this->checkoutSession->getLastRealOrderId());
            $this->checkoutSession->getQuote()->setIsActive(false)->save();
        }

        $this->_logger->debug($pre . 'eof');
    }

    /**
     * PayFast session instance getter
     *
     * @return Generic
     */
    protected function _getSession()
    {
        return $this->payfastSession;
    }

    /**
     * Return checkout session object
     *
     * @return \Magento\Checkout\Model\Session
     */
    protected function _getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    /**
     * Return checkout quote object
     *
     * @return Quote
     */
    protected function _getQuote()
    {
        if (!$this->_quote) {
            $this->_quote = $this->_getCheckoutSession()->getQuote();
        }

        return $this->_quote;
    }

    /**
     * Returns before_auth_url redirect parameter for customer session
     * @return null
     */
    public function getCustomerBeforeAuthUrl()
    {
        return;
    }

    /**
     * Returns a list of action flags [flag_key] => boolean
     * @return array
     */
    public function getActionFlagList()
    {
        return [ ];
    }

    /**
     * Returns login url parameter for redirect
     * @return string
     */
    public function getLoginUrl()
    {
        return $this->orderResourceModel->getLoginUrl();
    }

    /**
     * Returns action name which requires redirect
     * @return string
     */
    public function getRedirectActionName()
    {
        return 'index';
    }

    /**
     * Redirect to login page
     *
     * @return void
     */
    public function redirectLogin()
    {
        $this->_actionFlag->set('', 'no-dispatch', true);
        $this->customerSession->setBeforeAuthUrl($this->_redirect->getRefererUrl());
        $this->getResponse()->setRedirect(
            $this->urlHelper->addRequestParam($this->orderResourceModel->getLoginUrl(), [ 'context' => 'checkout' ])
        );
    }
}
