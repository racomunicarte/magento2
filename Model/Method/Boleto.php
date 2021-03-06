<?php
namespace Ipag\Payment\Model\Method;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Ipag\Ipag;
use Magento\Quote\Api\Data\PaymentInterface;
use \Magento\Framework\Exception\LocalizedException;
use \Magento\Sales\Model\Order\Payment;

class Boleto extends \Magento\Payment\Model\Method\Cc
{
    const ROUND_UP = 100;
    protected $_canAuthorize = true;
    protected $_canCapture = false;
    protected $_canRefund = false;
    protected $_code = 'ipagboleto';
    protected $_isGateway = true;
    protected $_canCapturePartial = false;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid = true;
    protected $_canCancel = true;
    protected $_canUseForMultishipping = false;
    protected $_countryFactory;
    protected $_supportedCurrencyCodes = ['BRL'];
    protected $_cart;
    protected $_ipagHelper;
    protected $logger;
    protected $_infoBlockType = 'Ipag\Payment\Block\Info\Boleto';
    protected $_ipagInvoiceInstallments;
    protected $_storeManager;
    protected $_date;

    /**
     * Constructor
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Ipag\Payment\Helper\Data $ipagHelper
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Locale\ResolverInterface $resolver
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Checkout\Model\Session $session
     * @param \Ipag\Payment\Logger\Logger $payexLogger
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Ipag\Payment\Helper\Data $ipagHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Locale\ResolverInterface $resolver,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Checkout\Model\Session $session,
        \Magento\Checkout\Model\Cart $cart,
        \Ipag\Payment\Logger\Logger $ipagLogger,
        \Ipag\Payment\Model\IpagInvoiceInstallments $ipagInvoiceInstallments,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $moduleList,
            $localeDate,
            $resource,
            $resourceCollection,
            $data
        );
        $this->scopeConfig = $scopeConfig;
        $this->_ipagHelper = $ipagHelper;
        $this->_cart = $cart;
        $this->logger = $ipagLogger;
        $this->_ipagInvoiceInstallments = $ipagInvoiceInstallments;
        $this->_storeManager = $storeManager;
        $this->_date = $date;
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData)) {
            return $this;
        }
        $infoInstance = $this->getInfoInstance();
        $currentData = $data->getAdditionalData();
        foreach ($currentData as $key => $value) {
            if ($key === \Magento\Framework\Api\ExtensibleDataInterface::EXTENSION_ATTRIBUTES_KEY) {
                continue;
            }
            $infoInstance->setAdditionalInformation($key, $value);
        }
        return $this;
    }

    public function validate()
    {
        $ipag = $this->_ipagHelper->AuthorizationValidate();

        return $this;
    }

    /**
     * Payment authorize
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        //parent::authorize($payment, $amount);
        $order = $payment->getOrder();

        try {

            if ($amount <= 0) {
                throw new LocalizedException(__('Invalid amount for authorization.'));
            }

            $ipag = $this->_ipagHelper->AuthorizationValidate();

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $InfoInstance = $this->getInfoInstance();
            $customer = $this->_ipagHelper->generateCustomerIpag($ipag, $order);
            $installments = $InfoInstance->getAdditionalInformation('installments');

            if ($installments > 1) {
                $additionalPrice = $this->_ipagHelper->addAdditionalPriceBoleto($order, $installments);
                $total = $order->getGrandTotal() + $additionalPrice;
                if ($additionalPrice >= 0.01) {
                    $brl = 'R$';
                    $formatted = number_format($additionalPrice, '2', ',', '.');
                    $totalformatted = number_format($total, '2', ',', '.');
                    $InfoInstance->setAdditionalInformation('interest', $brl.$formatted);
                    $InfoInstance->setAdditionalInformation('total_with_interest', $brl.$totalformatted);
                }
                $description = "Pedido #".$order->getIncrementId();
                $response = $this->generateInvoice($ipag, $customer, $total, $installments, $description);

                $json = json_decode($response, true);
                $this->logger->loginfo([$response], self::class.' RESPONSE RAW');
                $this->logger->loginfo($json, self::class.' RESPONSE JSON');
                $parcelas = [];
                if (is_array($json) || (is_object($json) && ($json instanceof \Traversable))) {
                    foreach ($json as $j => $k) {
                        if (is_array($k)) {
                            foreach ($k as $l => $m) {
                                if (is_array($m)) {
                                    foreach ($m as $n => $o) {
                                        if (is_array($o)) {
                                            foreach ($o as $p => $q) {
                                                if (is_array($q)) {
                                                    if ($j.'.'.$l.'.'.$n === 'attributes.installments.data') {
                                                        $parcelas[] = $q;
                                                    }
                                                    $q = json_encode($q);
                                                }
                                                $name = $j.'.'.$l.'.'.$n.'.'.$p;
                                                $json[$name] = $q;
                                                $InfoInstance->setAdditionalInformation($name, $q);
                                            }
                                        } else {
                                            $name = $j.'.'.$l.'.'.$n;
                                            $json[$name] = $o;
                                            $InfoInstance->setAdditionalInformation($name, $o);
                                        }
                                    }
                                } else {
                                    $name = $j.'.'.$l;
                                    $json[$name] = $m;
                                    $InfoInstance->setAdditionalInformation($name, $m);
                                }
                            }
                            unset($json[$j]);
                        } else {
                            $InfoInstance->setAdditionalInformation($j, $k);
                        }
                    }
                }
                if (is_array($json)) {
                    $payment->setTransactionId($json['id'])
                        ->setIsTransactionClosed(0)
                        ->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $json);
                }

                $this->logger->loginfo($parcelas, self::class.' INSTALLMENTS');
                if (!empty($parcelas)) {
                    $this->_ipagInvoiceInstallments->import($parcelas, $order->getIncrementId(), $json['id']);
                }
            } else {
                try {
                    $items = $this->_cart->getQuote()->getAllItems();
                    $InfoInstance = $this->getInfoInstance();
                    $cart = $this->_ipagHelper->addProductItemsIpag($ipag, $items);

                    $ipagPayment = $this->_ipagHelper->addPayBoletoIpag($ipag, $InfoInstance);
                    $ipagOrder = $this->_ipagHelper->createOrderIpag($order, $ipag, $cart, $ipagPayment, $customer, 0, 1);

                    $this->logger->loginfo($ipagOrder, self::class.' REQUEST');
                    $response = $ipag->transaction()->setOrder($ipagOrder)->execute();

                    $json = json_decode(json_encode($response), true);
                    $this->logger->loginfo([$response], self::class.' RESPONSE RAW');
                    $this->logger->loginfo($json, self::class.' RESPONSE JSON');
                    foreach ($json as $j => $k) {
                        if (is_array($k)) {
                            foreach ($k as $l => $m) {
                                $name = $j.'.'.$l;
                                $json[$name] = $m;
                                $InfoInstance->setAdditionalInformation($name, $m);
                            }
                            unset($json[$j]);
                        } else {
                            $InfoInstance->setAdditionalInformation($j, $k);
                        }
                    }

                    $payment->setTransactionId($response->tid)
                        ->setIsTransactionClosed(0)
                        ->setTransactionAdditionalInfo(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $json);
                } catch (\Exception $e) {
                    throw new LocalizedException(__('Payment failed '.$e->getMessage()));
                }
            }
        } catch (\Exception $e) {
            throw new LocalizedException(__('Payment failed '.$e->getMessage()));
        }
        return $this;
    }

    public function generateInvoice($ipag, $customer, $total, $installments, $description)
    {
        $payload = [
            "auth" => [
                $ipag->getAuthentication()->getIdentification(),
                $ipag->getAuthentication()->getApiKey(),
            ],
            "json" => [
                "name"     => $customer->getName(),
                "cpf_cnpj" => $customer->getTaxpayerId(),
                "email"    => $customer->getEmail(),
                "phone"    => $customer->getPhone(),
                "address"  => [
                    "street"   => $customer->getAddress()->getStreet(),
                    "number"   => $customer->getAddress()->getNumber(),
                    "district" => $customer->getAddress()->getNeighborhood(),
                    "city"     => $customer->getAddress()->getCity(),
                    "state"    => $customer->getAddress()->getState(),
                    "zip_code" => $customer->getAddress()->getZipCode(),
                ],
            ],
        ];
        $this->logger->loginfo($payload, self::class.' REQUEST CUSTOMER');
        $client = new Client(["base_uri" => $ipag->getEndpoint()->getUrl()]);
        $response = $client->request('POST', 'service/resources/customers', $payload);

        $responseBody = $response->getBody()->getContents();
        $statusCode = $response->getStatusCode();

        $this->logger->loginfo([$responseBody], self::class.' RESPONSE CUSTOMER');

        if ($statusCode == 201) {
            $responseArray = json_decode($responseBody, true);
            $id = $responseArray['id'];
            $venctoDias = (int) $this->_ipagHelper->getDueNumber();

            $payloadInvoice = [
                "auth" => [
                    $ipag->getAuthentication()->getIdentification(),
                    $ipag->getAuthentication()->getApiKey(),
                ],
                "json" => [
                    "is_active"     => true,
                    "type"          => "normal",
                    "frequency"     => 1,
                    "customer_id"   => $id,
                    "interval"      => "month",
                    "amount"        => $total,
                    "description"   => $description,
                    "starting_date" => $this->_date->gmtDate('Y-m-d', strtotime("+{$venctoDias} days")),
                    "callback_url"  => $this->getCallbackUrl(),
                    "installments"  => $installments,
                ],
            ];
            $this->logger->loginfo($payloadInvoice, self::class.' REQUEST INVOICE');

            $response = $client->request('POST', 'service/resources/invoices', $payloadInvoice);
            $responseBody = $response->getBody()->getContents();

            $this->logger->loginfo([$responseBody], self::class.' RESPONSE INVOICE');

            return $responseBody;
        } else {
            return $responseBody;
        }
    }

    public function queryInvoice($ipag_id)
    {
        $ipag = $this->_ipagHelper->AuthorizationValidate();
        $payloadInvoice = [
            "auth"  => [
                $ipag->getAuthentication()->getIdentification(),
                $ipag->getAuthentication()->getApiKey(),
            ],
            "query" => [
                "id" => $ipag_id,
            ],
        ];

        $client = new Client(["base_uri" => $ipag->getEndpoint()->getUrl()]);
        $response = $client->request('GET', 'service/resources/invoices', $payloadInvoice);
        $responseBody = $response->getBody()->getContents();

        return $responseBody;
    }

    public function getCallbackUrl()
    {
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();
        return $baseUrl.'ipag/notification/Callback';
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if (!$this->isActive($quote ? $quote->getStoreId() : null)) {
            return false;
        }
        return true;
    }
}
