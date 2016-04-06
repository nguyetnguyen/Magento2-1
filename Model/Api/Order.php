<?php
/**
 *  Shippit Pty Ltd
 *
 *  NOTICE OF LICENSE
 *
 *  This source file is subject to the terms
 *  that is available through the world-wide-web at this URL:
 *  http://www.shippit.com/terms
 *
 *  @category   Shippit
 *  @copyright  Copyright (c) 2016 by Shippit Pty Ltd (http://www.shippit.com)
 *  @author     Matthew Muscat <matthew@mamis.com.au>
 *  @license    http://www.shippit.com/terms
 */

namespace Shippit\Shipping\Model\Api;

use \Shippit\Shipping\Model\Sync\Order as SyncOrder;

class Order extends \Magento\Framework\Model\AbstractModel
{
    protected $api;
    protected $helper;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $date;

    /**
     * @var \Shippit\Shipping\Model\Request\OrderFactory
     */
    protected $requestOrderFactory;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * Store Manager Interface
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManagerInterface;

    /**
     * App emulation model
     *
     * @var \Magento\Store\Model\App\Emulation
     */
    protected $appEmulation;

    /**
     * @param \Shippit\Shipping\Helper\Data $helper
     */
    public function __construct(
        \Shippit\Shipping\Helper\Sync\Order $helper,
        \Shippit\Shipping\Helper\Api $api,
        \Shippit\Shipping\Model\Request\OrderFactory $requestOrderFactory,
        \Shippit\Shipping\Model\Sync\OrderFactory $syncOrderFactory,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
        \Magento\Store\Model\App\Emulation $appEmulation
    ) {
        $this->helper = $helper;
        $this->api = $api;
        $this->requestOrderFactory = $requestOrderFactory;
        $this->syncOrderFactory = $syncOrderFactory;
        $this->messageManager = $messageManager;
        $this->date = $date;
        $this->storeManagerInterface = $storeManagerInterface;
        $this->appEmulation = $appEmulation;

        // return parent::__construct();
    }

    public function run()
    {
        if (!$this->helper->isActive()) {
            return $this;
        }

        // get all stores, as we will emulate each storefront for integration run
        $stores = $this->storeManagerInterface->getStores();

        foreach ($stores as $store) {
            $storeId = $store->getStoreId();

            // Start Store Emulation
            $environment = $this->appEmulation->startEnvironmentEmulation($storeId);

            $syncOrders = $this->getSyncOrders($storeId);

            foreach ($syncOrders as $syncOrder) {
                $this->sync($syncOrder);
            }

            // Stop Store Emulation
            $this->appEmulation->stopEnvironmentEmulation($environment);
        }
    }

    /**
     * Get a list of sync orders pending sync
     * @return [type] [description]
     */
    public function getSyncOrders($storeId)
    {
        $collection = $this->syncOrderFactory->create()
            ->getCollection();

        return $collection->join(
                array('order' => $collection->getTable('sales_order')),
                'order.entity_id = main_table.order_id',
                array(),
                null,
                'left'
            )
            ->addFieldToFilter('main_table.status', SyncOrder::STATUS_PENDING)
            ->addFieldToFilter('main_table.attempt_count', array('lt' => SyncOrder::SYNC_MAX_ATTEMPTS))
            ->addFieldToFilter('order.state', array('eq' => \Magento\Sales\Model\Order::STATE_PROCESSING))
            ->addFieldToFilter('order.store_id', array('eq' => $storeId));
    }
    
    public function sync($syncOrder, $displayNotifications = false)
    {
        if (!$this->helper->isActive()) {
            return false;
        }

        try {
            // increase the attempt count by 1
            $syncOrder->setAttemptCount($syncOrder->getAttemptCount() + 1);

            // Build the order request
            $orderRequest = $this->requestOrderFactory->create()
                ->processSyncOrder($syncOrder);
                
            $apiResponse = $this->api->sendOrder($orderRequest);

            // Add the order tracking details to
            // the order comments and save
            $order = $syncOrder->getOrder();
            $comment = __('Order Synced with Shippit - ' . $apiResponse->tracking_number);
            $order->addStatusHistoryComment($comment)
                ->setIsVisibleOnFront(false)
                ->save();

            // Update the order to be marked as synced
            $syncOrder->setStatus(SyncOrder::STATUS_SYNCED)
                ->setTrackingNumber($apiResponse->tracking_number)
                ->setSyncedAt($this->date->gmtDate())
                ->save();

            if ($displayNotifications) {
                $this->messageManager->addSuccess(__('Order ' . $order->getIncrementId() . ' Synced with Shippit - ' . $apiResponse->tracking_number));
            }
        }
        catch (Exception $e) {
            $this->logger->log('API - Order Sync Request Failed', $e->getMessage(), \Zend_Log::ERR);
            $this->logger->logException($e);

            // Fail the sync item if it's breached the max attempts
            if ($syncOrder->getAttemptCount() > SyncOrder::SYNC_MAX_ATTEMPTS) {
                $syncItem->setStatus(SyncOrder::STATUS_FAILED);
            }

            // save the sync item attempt count
            $syncOrder->save();

            if ($displayNotifications) {
                $this->messageManager->addError(__('Order ' . $order->getIncrementId() . ' was not Synced with Shippit - ' . $e->getMessage()));
            }

            return false;
        }

        return true;
    }
}