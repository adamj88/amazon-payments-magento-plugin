<?php
/**
 * Amazon Payments Checkout Controller
 *
 * @category    Amazon
 * @package     Amazon_Payments
 * @copyright   Copyright (c) 2014 Amazon.com
 * @license     http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

abstract class Amazon_Payments_Controller_Checkout extends Mage_Checkout_Controller_Action
{
    protected $_amazonOrderReferenceId;
    protected $_checkoutUrl;

    /**
     * Return Amazon Order Reference Id
     */
    public function getAmazonOrderReferenceId()
    {
        return $this->_amazonOrderReferenceId;
    }

    /**
     * Check query string paramters for order reference and/or access token
     */
    public function preDispatch()
    {
        parent::preDispatch();
        $this->_amazonOrderReferenceId = $this->getRequest()->getParam('amazon_order_reference_id');

        if (!$this->_amazonOrderReferenceId) {
            $this->_amazonOrderReferenceId = Mage::getSingleton('checkout/session')->getAmazonOrderReferenceId();
        }
        else {
            Mage::getSingleton('checkout/session')->setAmazonOrderReferenceId($this->_amazonOrderReferenceId);
        }


        // User is logging in...

        $token = $this->getRequest()->getParam('access_token');

        if ($token) {
            $_amazonLogin = Mage::getModel('amazon_login/customer');

            if (!Mage::getSingleton('customer/session')->isLoggedIn()) {
                if (!$this->_getConfig()->isGuestCheckout() || !$this->_getOnepage()->getQuote()->isAllowedGuestCheckout()) {
                    $customer = $_amazonLogin->loginWithToken($token, $this->_checkoutUrl);
                }
                // Guest
                else {
                    $amazonProfile = $_amazonLogin->getAmazonProfile($token);
                    if ($amazonProfile && isset($amazonProfile['email'])) {
                        Mage::getSingleton('checkout/session')->setCustomerEmail($amazonProfile['email']);
                        Mage::getSingleton('checkout/session')->setCustomerName($amazonProfile['name']);
                    }
                }
            }

            Mage::getSingleton('checkout/session')->setAmazonAccessToken($token);
        }

        // Redirect to clean URL
        if ($this->getRequest()->getParam('access_token')) {
            $this->_redirect($this->_checkoutUrl, array('_secure' => true));
            return;
        }
    }

    /**
     * Clear Amazon session data
     */
    public function clearSession()
    {
        Mage::helper('amazon_payments/data')->clearSession();
    }


    /**
     * Validate ajax request and redirect on failure
     *
     * @return bool
     */
    protected function _expireAjax()
    {
        if (!$this->_getCheckout()->getQuote()->hasItems()
            || $this->_getCheckout()->getQuote()->getHasError()
            || $this->_getCheckout()->getQuote()->getIsMultiShipping()
        ) {
            $this->_ajaxRedirectResponse();
            return true;
        }
        $action = $this->getRequest()->getActionName();
        if (Mage::getSingleton('checkout/session')->getCartWasUpdated(true)
            && !in_array($action, array('index'))
        ) {
            $this->_ajaxRedirectResponse();
            return true;
        }
        return false;
    }

    /**
     * Get Order by quoteId
     *
     * @throws Mage_Payment_Model_Info_Exception
     * @return Mage_Sales_Model_Order
     */
    protected function _getOrder()
    {
        if (is_null($this->_order)) {
            $this->_order = Mage::getModel('sales/order')->load($this->_getCheckout()->getQuote()->getId(), 'quote_id');
            if (!$this->_order->getId()) {
                throw new Mage_Payment_Model_Info_Exception(Mage::helper('core')->__("Can not create invoice. Order was not found."));
            }
        }
        return $this->_order;
    }


    /**
     * Get Amazon API
     *
     * @return Amazon_Payments_Model_Api
     */
    protected function _getApi() {
        return Mage::getModel('amazon_payments/api');
    }

    /**
     * Get Payments config
     */
    protected function _getConfig() {
        return Mage::getModel('amazon_payments/config');
    }


    /**
     * Send Ajax redirect response
     *
     * @return Amazon_Payments_CheckoutController
     */
    protected function _ajaxRedirectResponse()
    {
        $this->getResponse()
            ->setHeader('HTTP/1.1', '403 Session Expired')
            ->setHeader('Login-Required', 'true')
            ->sendResponse();
        return $this;
    }

    /**
     * Get checkout model
     *
     * @return Amazon_Payments_Model_Type_Checkout
     */
    protected function _getCheckout()
    {
        return Mage::getSingleton('amazon_payments/type_checkout');
    }

    /**
     * Get onepage model
     *
     * @return Amazon_Payments_Model_Type_Checkout
     */
    protected function _getOnepage()
    {
        return Mage::getSingleton('checkout/type_onepage');
    }


    /**
     * Save shipping address based on Amazon Order Reference (e.g. address book click)
     */
    protected function _saveShipping()
    {
        if ($this->getAmazonOrderReferenceId()) {

            $orderReferenceDetails = $this->_getApi()->getOrderReferenceDetails($this->getAmazonOrderReferenceId(), Mage::getSingleton('checkout/session')->getAmazonAccessToken());
            $address = $orderReferenceDetails->getDestination()->getPhysicalDestination();

            // Split name into first/last
            //$name      = (Mage::getSingleton('customer/session')->isLoggedIn()) ? $address->getName() : Mage::getSingleton('checkout/session')->getCustomerName();
            $name      = $address->getName();
            $firstName = substr($name, 0, strrpos($name, ' '));
            $lastName  = substr($name, strlen($firstName) + 1);

            // Find Mage state/region ID
            $regionModel = Mage::getModel('directory/region')->loadByCode($address->getStateOrRegion(), $address->getCountryCode());
            $regionId    = $regionModel->getId();

            $data = array(
                'firstname'   => $firstName,
                'lastname'    => $lastName,
                'street'      => array($address->getAddressLine1(), $address->getAddressLine2()),
                'city'        => $address->getCity(),
                'region_id'   => $regionId,
                'postcode'    => $address->getPostalCode(),
                'country_id'  => $address->getCountryCode(),
                'telephone'   => $address->getPhone(),
                'use_for_shipping' => true,
            );

            if ($email = Mage::getSingleton('checkout/session')->getCustomerEmail()) {
                $data['email'] = $email;
            }

            return $this->_getCheckout()->saveShipping($data);
        }
    }

}
