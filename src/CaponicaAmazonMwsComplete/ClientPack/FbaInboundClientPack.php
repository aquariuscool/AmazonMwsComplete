<?php

namespace CaponicaAmazonMwsComplete\ClientPack;

use CaponicaAmazonMwsComplete\AmazonClient\FbaInboundClient;
use CaponicaAmazonMwsComplete\ClientPool\MwsClientPoolConfig;
use CaponicaAmazonMwsComplete\Concerns\ProvidesServiceUrlSuffix;
use CaponicaAmazonMwsComplete\Concerns\SignsRequestArray;
use CaponicaAmazonMwsComplete\Domain\Inbound\Address;
use CaponicaAmazonMwsComplete\Domain\Inbound\AsinList;
use CaponicaAmazonMwsComplete\Domain\Inbound\InboundShipmentHeader;
use CaponicaAmazonMwsComplete\Domain\Inbound\InboundShipmentItemList;
use CaponicaAmazonMwsComplete\Domain\Inbound\InboundShipmentPlanRequestItemList;
use CaponicaAmazonMwsComplete\Domain\Inbound\PackageIdentifiers;
use CaponicaAmazonMwsComplete\Domain\Inbound\SellerSkuList;
use CaponicaAmazonMwsComplete\Domain\Inbound\TransportDetailInput;
use CaponicaAmazonMwsComplete\Domain\Throttle\ThrottleAwareClientPackInterface;
use CaponicaAmazonMwsComplete\Domain\Throttle\ThrottledRequestManager;
use CaponicaAmazonMwsComplete\Service\LoggerService;
use DateTime;
use Exception;
use FBAInboundServiceMWS_Model_CreateInboundShipmentPlanResponse;
use FBAInboundServiceMWS_Model_CreateInboundShipmentResponse;
use FBAInboundServiceMWS_Model_GetInboundGuidanceForSKUResponse;
use FBAInboundServiceMWS_Model_ListInboundShipmentItemsByNextTokenResponse;
use FBAInboundServiceMWS_Model_ListInboundShipmentsByNextTokenResponse;
use FBAInboundServiceMWS_Model_ListInboundShipmentsResponse;
use FBAInboundServiceMWS_Model_UpdateInboundShipmentResponse;
use Psr\Log\LoggerInterface;

class FbaInboundClientPack extends FbaInboundClient implements ThrottleAwareClientPackInterface
{
    use SignsRequestArray, ProvidesServiceUrlSuffix;

    const SERVICE_NAME = 'FulfillmentInboundShipment';

    const PARAM_MARKETPLACE_ID = 'MarketplaceId';
    const PARAM_MERCHANT = 'SellerId';
    const PARAM_SELLER_ID = 'SellerId';   // Alias for PARAM_MERCHANT
    const PARAM_MWS_AUTH_TOKEN = 'MWSAuthToken';
    const PARAM_SHIP_FROM_ADDRESS = 'ShipFromAddress';
    const PARAM_SHIP_TO_COUNTRY_CODE = 'ShipToCountryCode';
    const PARAM_SHIP_TO_COUNTRY_SUBDIVISION_CODE = 'ShipToCountrySubdivisionCode';
    const PARAM_LABEL_PREP_PREFERENCE = 'LabelPrepPreference';
    const PARAM_INBOUND_SHIPMENT_PLAN_REQUEST_ITEMS = 'InboundShipmentPlanRequestItems';
    const PARAM_SHIPMENT_ID = 'ShipmentId';
    const PARAM_SHIPMENT_ID_LIST = 'ShipmentIdList';
    const PARAM_SHIPMENT_STATUS_LIST = 'ShipmentStatusList';
    const PARAM_INBOUND_SHIPMENT_HEADER = 'InboundShipmentHeader';
    const PARAM_INBOUND_SHIPMENT_ITEMS = 'InboundShipmentItems';
    const PARAM_LAST_UPDATED_AFTER = 'LastUpdatedAfter';
    const PARAM_LAST_UPDATED_BEFORE = 'LastUpdatedBefore';
    const PARAM_SELLER_SKU_LIST = 'SellerSKUList';
    const PARAM_ASIN_LIST = 'ASINList';
    const PARAM_NEXT_TOKEN = 'NextToken';
    const PARAM_IS_PARTNERED = 'IsPartnered';
    const PARAM_SHIPMENT_TYPE = 'ShipmentType';
    const PARAM_TRANSPORT_DETAILS = 'TransportDetails';
    const PARAM_PAGE_TYPE = 'PageType';
    const PARAM_PACKAGE_LABELS_TO_PRINT = 'PackageLabelsToPrint';
    const PARAM_NUMBER_OF_PACKAGES = 'NumberOfPackages';

    const METHOD_CREATE_INBOUND_SHIPMENT_PLAN = 'createInboundShipmentPlan';
    const METHOD_CREATE_INBOUND_SHIPMENT = 'createInboundShipment';
    const METHOD_UPDATE_INBOUND_SHIPMENT = 'updateInboundShipment';
    const METHOD_LIST_INBOUND_SHIPMENTS = 'listInboundShipments';
    const METHOD_LIST_INBOUND_SHIPMENTS_BY_NEXT_TOKEN = 'listInboundShipmentsByNextToken';
    const METHOD_LIST_INBOUND_SHIPMENT_ITEMS = 'listInboundShipmentItems';
    const METHOD_LIST_INBOUND_SHIPMENT_ITEMS_BY_NEXT_TOKEN = 'listInboundShipmentItemsByNextToken';
    const METHOD_GET_INBOUND_GUIDANCE_FOR_SKU = 'getInboundGuidanceForSKU';
    const METHOD_GET_INBOUND_GUIDANCE_FOR_ASIN = 'getInboundGuidanceForASIN';
    const METHOD_PUT_TRANSPORT_CONTENT = 'putTransportContent';
    const METHOD_GET_TRANSPORT_CONTENT = 'getTransportContent';
    const METHOD_GET_UNIQUE_PACKAGE_LABELS = 'getUniquePackageLabels';
    const METHOD_GET_PACKAGE_LABELS = 'getPackageLabels';

    const STATUS_WORKING    = 'WORKING';
    const STATUS_SHIPPED    = 'SHIPPED';
    const STATUS_IN_TRANSIT = 'IN_TRANSIT';
    const STATUS_DELIVERED  = 'DELIVERED';
    const STATUS_CHECKED_IN = 'CHECKED_IN';
    const STATUS_RECEIVING  = 'RECEIVING';
    const STATUS_CLOSED     = 'CLOSED';
    const STATUS_CANCELLED  = 'CANCELLED';
    const STATUS_DELETED    = 'DELETED';
    const STATUS_ERROR      = 'ERROR';

    /**
     * PackageLabel_Letter_2 - Two labels per US Letter label sheet.
     *   This is the only valid value for Amazon-partnered shipments in the US that use UPS as the carrier.
     *   Supported in Canada and the US.
     * PackageLabel_Letter_6 - Six labels per US Letter label sheet.
     *   This is the only valid value for non-Amazon-partnered shipments in the US.
     *   Supported in Canada and the US.
     * PackageLabel_A4_2 - Two labels per A4 label sheet.
     *   Supported in France, Germany, Italy, Spain, and the UK.
     * PackageLabel_A4_4 - Four labels per A4 label sheet.
     *   Supported in France, Germany, Italy, Spain, and the UK.
     * PackageLabel_Plain_Paper. One label per sheet of US Letter paper.
     *   Only for non-Amazon-partnered shipments. Supported in all marketplaces.
     */
    const PAGE_TYPE_PACKAGE_LABEL_LETTER_2 = 'PackageLabel_Letter_2';
    const PAGE_TYPE_PACKAGE_LABEL_LETTER_6 = 'PackageLabel_Letter_6';
    const PAGE_TYPE_PACKAGE_LABEL_A4_2 = 'PackageLabel_A4_2';
    const PAGE_TYPE_PACKAGE_LABEL_A4_4 = 'PackageLabel_A4_4';
    const PAGE_TYPE_PACKAGE_LABEL_PLAIN_PAPER = 'PackageLabel_Plain_Paper';

    public static function getAllShipmentStatuses() {
        return [
            self::STATUS_WORKING,
            self::STATUS_SHIPPED,
            self::STATUS_IN_TRANSIT,
            self::STATUS_DELIVERED,
            self::STATUS_CHECKED_IN,
            self::STATUS_RECEIVING,
            self::STATUS_CLOSED,
            self::STATUS_CANCELLED,
            self::STATUS_DELETED,
            self::STATUS_ERROR,
        ];
    }
    /**
     * The MWS MarketplaceID string used in API connections.
     *
     * @var string
     */
    protected $marketplaceId;

    /**
     * The MWS SellerID string used in API connections.
     *
     * @var string
     */
    protected $sellerId;

    /**
     * MWSAuthToken, only needed when working with (3rd party) client accounts which provide an Auth Token.
     *
     * @var string
     */
    protected $authToken = null;

    /**
     * @var ThrottledRequestManager
     */
    private $throttleManager;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(MwsClientPoolConfig $poolConfig, LoggerInterface $logger = null)
    {
        $this->marketplaceId = $poolConfig->getMarketplaceId();
        $this->sellerId      = $poolConfig->getSellerId();
        $this->authToken     = $poolConfig->getAuthToken();
        $this->logger        = $logger;

        $this->initThrottleManager();

        parent::__construct(
            $poolConfig->getAccessKey(),
            $poolConfig->getSecretKey(),
            $poolConfig->getApplicationName(),
            $poolConfig->getApplicationVersion(),
            $poolConfig->getConfigForOrder($this->getServiceUrlSuffix())
        );
    }

    /**
     * Initialize the throttle manager with method throttle properties.
     */
    public function initThrottleManager()
    {
        $this->throttleManager = new ThrottledRequestManager([
            self::METHOD_CREATE_INBOUND_SHIPMENT_PLAN              => [30, 2],
            self::METHOD_CREATE_INBOUND_SHIPMENT                   => [30, 2],
            self::METHOD_UPDATE_INBOUND_SHIPMENT                   => [30, 2],
            self::METHOD_LIST_INBOUND_SHIPMENTS                    => [30, 2],
            self::METHOD_LIST_INBOUND_SHIPMENTS_BY_NEXT_TOKEN      => [null, null, null, self::METHOD_LIST_INBOUND_SHIPMENTS],
            self::METHOD_LIST_INBOUND_SHIPMENT_ITEMS               => [30, 2],
            self::METHOD_LIST_INBOUND_SHIPMENT_ITEMS_BY_NEXT_TOKEN => [null, null, null, self::METHOD_LIST_INBOUND_SHIPMENT_ITEMS],
            self::METHOD_GET_INBOUND_GUIDANCE_FOR_SKU              => [200, 200],
            self::METHOD_GET_INBOUND_GUIDANCE_FOR_ASIN             => [200, 200],
            self::METHOD_PUT_TRANSPORT_CONTENT                     => [30, 2],
            self::METHOD_GET_TRANSPORT_CONTENT                     => [30, 2],
            self::METHOD_GET_PACKAGE_LABELS                        => [30, 2],
            self::METHOD_GET_UNIQUE_PACKAGE_LABELS                 => [30, 2],
        ]);
    }

    /**
     * @return ThrottledRequestManager
     */
    public function getThrottleManager()
    {
        return $this->throttleManager;
    }

    /**
     * Create Inbound Shipment Plan
     * Plans inbound shipments for a set of items.  Registers identifiers if needed,
     * and assigns ShipmentIds for planned shipments.
     * When all the items are not all in the same category (e.g. some sortable, some
     * non-sortable) it may be necessary to create multiple shipments (one for each
     * of the shipment groups returned).
     *
     * @param Address                            $shipFromAddress
     * @param string                             $shipToCountryCode
     * @param InboundShipmentPlanRequestItemList $itemList
     * @param string|null                        $shipToCountrySubdivisionCode
     * @param string|null                        $labelPrepPreference
     *
     * @throws Exception
     *
     * @return FBAInboundServiceMWS_Model_CreateInboundShipmentPlanResponse
     */
    public function callCreateInboundShipmentPlan(Address $shipFromAddress,
        $shipToCountryCode,
        InboundShipmentPlanRequestItemList $itemList,
        $shipToCountrySubdivisionCode = null,
        $labelPrepPreference = null)
    {
        $requestArray = [
            self::PARAM_SHIP_FROM_ADDRESS                   => $shipFromAddress->toArray(),
            self::PARAM_SHIP_TO_COUNTRY_CODE                => $shipToCountryCode,
            self::PARAM_INBOUND_SHIPMENT_PLAN_REQUEST_ITEMS => ['member' => $itemList->toArray()],
        ];

        if ( ! empty($shipToCountrySubdivisionCode)) {
            $requestArray[self::PARAM_SHIP_TO_COUNTRY_SUBDIVISION_CODE] = $shipToCountrySubdivisionCode;
        }

        if ( ! empty($labelPrepPreference)) {
            $requestArray[self::PARAM_LABEL_PREP_PREFERENCE] = $labelPrepPreference;
        }

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_CREATE_INBOUND_SHIPMENT_PLAN, $requestArray);
    }

    /**
     * Create Inbound Shipment
     * Creates an inbound shipment. It may include up to 200 items.
     * The initial status of a shipment will be set to 'Working'.
     * This operation will simply return a shipment Id upon success,
     * otherwise an explicit error will be returned.
     * More items may be added using the Update call.
     *
     * @param string                  $shipmentId
     * @param InboundShipmentHeader   $inboundShipmentHeader
     * @param InboundShipmentItemList $inboundShipmentItems
     *
     * @throws Exception
     *
     * @return FBAInboundServiceMWS_Model_CreateInboundShipmentResponse
     */
    public function callCreateInboundShipment($shipmentId,
        InboundShipmentHeader $inboundShipmentHeader,
        InboundShipmentItemList $inboundShipmentItems)
    {
        $requestArray = [
            self::PARAM_SHIPMENT_ID             => $shipmentId,
            self::PARAM_INBOUND_SHIPMENT_HEADER => $inboundShipmentHeader->toArray(),
            self::PARAM_INBOUND_SHIPMENT_ITEMS  => ['member' => $inboundShipmentItems->toArray()],
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_CREATE_INBOUND_SHIPMENT, $requestArray);
    }

    /**
     * Update Inbound Shipment
     * Updates an pre-existing inbound shipment specified by the
     * ShipmentId. It may include up to 200 items.
     * If InboundShipmentHeader is set. it replaces the header information
     * for the given shipment.
     * If InboundShipmentItems is set. it adds, replaces and removes
     * the line time to inbound shipment.
     * For non-existing item, it will add the item for new line item;
     * For existing line items, it will replace the QuantityShipped for the item.
     * For QuantityShipped = 0, it indicates the item should be removed from the shipment
     *
     * This operation will simply return a shipment Id upon success,
     * otherwise an explicit error will be returned.
     *
     * @param string                  $shipmentId
     * @param InboundShipmentHeader   $inboundShipmentHeader
     * @param InboundShipmentItemList $inboundShipmentItems
     *
     * @throws Exception
     *
     * @return FBAInboundServiceMWS_Model_UpdateInboundShipmentResponse
     */
    public function callUpdateInboundShipment($shipmentId,
        InboundShipmentHeader $inboundShipmentHeader,
        InboundShipmentItemList $inboundShipmentItems)
    {
        $requestArray = [
            self::PARAM_SHIPMENT_ID             => $shipmentId,
            self::PARAM_INBOUND_SHIPMENT_HEADER => $inboundShipmentHeader->toArray(),
            self::PARAM_INBOUND_SHIPMENT_ITEMS  => ['member' => $inboundShipmentItems->toArray()],
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_UPDATE_INBOUND_SHIPMENT, $requestArray);
    }

    /**
     * List Inbound Shipments
     * Get the first set of inbound shipments created by a Seller according to
     * the specified shipment status or the specified shipment Id. A NextToken
     * is also returned to further iterate through the Seller's remaining
     * shipments. If a NextToken is not returned, it indicates the end-of-data.
     * At least one of ShipmentStatusList and ShipmentIdList must be passed in.
     * if both are passed in, then only shipments that match the specified
     * shipment Id and specified shipment status will be returned.
     * the LastUpdatedBefore and LastUpdatedAfter are optional, they are used
     * to filter results based on last update time of the shipment.
     *
     * @param string[]      $shipmentIdList
     * @param string[]      $shipmentStatusList
     * @param DateTime|null $lastUpdatedAfter
     * @param DateTime|null $lastUpdatedBefore
     *
     * @throws Exception
     *
     * @return FBAInboundServiceMWS_Model_ListInboundShipmentsResponse
     */
    public function callListInboundShipments(
        $shipmentIdList = [],
        $shipmentStatusList = [],
        DateTime $lastUpdatedAfter = null,
        DateTime $lastUpdatedBefore = null)
    {
        $requestArray = [];

        if ( ! empty($shipmentIdList)) {
            $requestArray[self::PARAM_SHIPMENT_ID_LIST] = ['member' => $shipmentIdList];
        }

        if (empty($shipmentStatusList)) {
            $shipmentStatusList = self::getAllShipmentStatuses();
        }

        $requestArray[self::PARAM_SHIPMENT_STATUS_LIST] = ['member' => $shipmentStatusList];

        if ( ! empty($lastUpdatedAfter)) {
            $requestArray[self::PARAM_LAST_UPDATED_AFTER] = $lastUpdatedAfter;
        }

        if ( ! empty($lastUpdatedBefore)) {
            $requestArray[self::PARAM_LAST_UPDATED_BEFORE] = $lastUpdatedBefore;
        }

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_LIST_INBOUND_SHIPMENTS, $requestArray);
    }

    /**
     * List Inbound Shipments By Next Token
     * Gets the next set of inbound shipments created by a Seller with the
     * NextToken which can be used to iterate through the remaining inbound
     * shipments. If a NextToken is not returned, it indicates the end-of-data.
     *
     * @param $nextToken
     *
     * @throws Exception
     *
     * @return FBAInboundServiceMWS_Model_ListInboundShipmentsByNextTokenResponse
     */
    public function callListInboundShipmentsByNextToken($nextToken)
    {
        $requestArray = [
            self::PARAM_NEXT_TOKEN => $nextToken,
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_LIST_INBOUND_SHIPMENTS_BY_NEXT_TOKEN, $requestArray);
    }

    /**
     * List Inbound Shipment Items
     * Gets the first set of inbound shipment items for the given ShipmentId or
     * all inbound shipment items updated between the given date range.
     * A NextToken is also returned to further iterate through the Seller's
     * remaining inbound shipment items. To get the next set of inbound
     * shipment items, you must call ListInboundShipmentItemsByNextToken and
     * pass in the 'NextToken' this call returned. If a NextToken is not
     * returned, it indicates the end-of-data. Use LastUpdatedBefore
     * and LastUpdatedAfter to filter results based on last updated time.
     * Either the ShipmentId or a pair of LastUpdatedBefore and LastUpdatedAfter
     * must be passed in. if ShipmentId is set, the LastUpdatedBefore and
     * LastUpdatedAfter will be ignored.
     *
     * @param string|null   $shipmentId
     * @param DateTime|null $lastUpdatedAfter
     * @param DateTime|null $lastUpdatedBefore
     *
     * @throws Exception
     *
     * @return \FBAInboundServiceMWS_Model_ListInboundShipmentItemsResponse
     */
    public function callListInboundShipmentItems($shipmentId = null,
        DateTime $lastUpdatedAfter = null,
        DateTime $lastUpdatedBefore = null)
    {
        $requestArray = [];

        if ( ! empty($shipmentId)) {
            $requestArray[self::PARAM_SHIPMENT_ID] = $shipmentId;
        }

        if ( ! empty($lastUpdatedAfter)) {
            $requestArray[self::PARAM_LAST_UPDATED_AFTER] = $lastUpdatedAfter;
        }

        if ( ! empty($lastUpdatedBefore)) {
            $requestArray[self::PARAM_LAST_UPDATED_BEFORE] = $lastUpdatedBefore;
        }

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_LIST_INBOUND_SHIPMENT_ITEMS, $requestArray);
    }

    /**
     * List Inbound Shipment Items By Next Token
     * Gets the next set of inbound shipment items with the NextToken
     * which can be used to iterate through the remaining inbound shipment
     * items. If a NextToken is not returned, it indicates the end-of-data.
     * You must first call ListInboundShipmentItems to get a 'NextToken'.
     *
     * @param $nextToken
     *
     * @throws Exception
     *
     * @return FBAInboundServiceMWS_Model_ListInboundShipmentItemsByNextTokenResponse
     */
    public function callListInboundShipmentItemsByNextToken($nextToken)
    {
        $requestArray = [
            self::PARAM_NEXT_TOKEN => $nextToken,
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_LIST_INBOUND_SHIPMENT_ITEMS_BY_NEXT_TOKEN, $requestArray);
    }

    /**
     * @param SellerSkuList $skuList
     *
     * @throws Exception
     *
     * @return FBAInboundServiceMWS_Model_GetInboundGuidanceForSKUResponse
     */
    public function callGetInboundGuidanceForSKU(SellerSkuList $skuList)
    {
        $requestArray = [
            self::PARAM_SELLER_SKU_LIST => ['Id' => $skuList->toArray()],
            self::PARAM_MARKETPLACE_ID  => $this->marketplaceId,
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_GET_INBOUND_GUIDANCE_FOR_SKU, $requestArray);
    }

    /**
     * @param AsinList $asinList
     *
     * @throws Exception
     *
     * @return \FBAInboundServiceMWS_Model_GetInboundGuidanceForASINResponse
     */
    public function callGetInboundGuidanceForASIN(AsinList $asinList)
    {
        $requestArray = [
            self::PARAM_ASIN_LIST => ['Id' => $asinList->toArray()],
            self::PARAM_MARKETPLACE_ID  => $this->marketplaceId,
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_GET_INBOUND_GUIDANCE_FOR_ASIN, $requestArray);
    }

    /**
     * @param string               $shipmentId
     * @param bool                 $isPartnered
     * @param string               $shipmentType
     * @param TransportDetailInput $transportDetailInput
     *
     * @throws Exception
     *
     * @return \FBAInboundServiceMWS_Model_PutTransportContentResponse
     */
    public function callPutTransportContent($shipmentId, $isPartnered, $shipmentType, TransportDetailInput $transportDetailInput)
    {
        $requestArray = [
            self::PARAM_SHIPMENT_ID       => $shipmentId,
            self::PARAM_IS_PARTNERED      => $isPartnered,
            self::PARAM_SHIPMENT_TYPE     => $shipmentType,
            self::PARAM_TRANSPORT_DETAILS => $transportDetailInput->toArray(),
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_PUT_TRANSPORT_CONTENT, $requestArray);
    }

    /**
     * @param string $shipmentId
     *
     * @throws Exception
     *
     * @return \FBAInboundServiceMWS_Model_GetTransportContentResponse
     */
    public function callGetTransportContent($shipmentId)
    {
        $requestArray = [
            self::PARAM_SHIPMENT_ID       => $shipmentId
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_GET_TRANSPORT_CONTENT, $requestArray);
    }

    /**
     * Get Package Labels
     * Returns package labels.
     *
     * @param string      $shipmentId
     * @param string      $pageType          One of the PAGE_TYPE_XYZ values
     * @param int|null    $numberOfPackages  Indicates the number of packages in the shipment.
     *
     * @throws \FBAInboundServiceMWS_Exception
     *
     * @return \FBAInboundServiceMWS_Model_GetPackageLabelsResponse
     */

    public function callGetPackageLabels($shipmentId, $pageType, $numberOfPackages = null)
    {
        $requestArray = [
            self::PARAM_SHIPMENT_ID => $shipmentId,
            self::PARAM_PAGE_TYPE => $pageType,
        ];

        if ( ! empty($numberOfPackages)) {
            $requestArray[self::PARAM_NUMBER_OF_PACKAGES] = $numberOfPackages;
        }

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_GET_PACKAGE_LABELS, $requestArray);
    }


    /**
     * Get Unique Package Labels
     * Returns unique package labels for faster and more accurate shipment
     * processing at the Amazon fulfillment center.
     *
     * @param string      $shipmentId
     * @param string      $pageType          One of the PAGE_TYPE_XYZ values
     * @param PackageIdentifiers    $packageLabelsToPrint CartonId values previously passed using the FBA Inbound Shipment Carton Information Feed
     *
     * @throws \FBAInboundServiceMWS_Exception
     *
     * @return \FBAInboundServiceMWS_Model_GetUniquePackageLabelsResponse
     */
    public function callGetUniquePackageLabels($shipmentId, $pageType, $packageLabelsToPrint)
    {
        $requestArray = [
            self::PARAM_SHIPMENT_ID => $shipmentId,
            self::PARAM_PAGE_TYPE => $pageType,
            self::PARAM_PACKAGE_LABELS_TO_PRINT => ['member' => $packageLabelsToPrint->toArray()],
        ];

        $requestArray = $this->signArray($requestArray);

        return CaponicaClientPack::throttledCall($this, self::METHOD_GET_UNIQUE_PACKAGE_LABELS, $requestArray);
    }

    /**
     * Uses the Amazon API to call callGetPackageLabels() and return PDF content
     */
    public function retrievePackageLabelsFile($shipmentId, $pageType, $numberOfPackages)
    {
        try {
            $response = $this->callGetPackageLabels($shipmentId, $pageType, $numberOfPackages);
        } catch (\FBAInboundServiceMWS_Exception $e) {
            if ('RequestThrottled' == $e->getErrorCode()) {
                $this->logMessage("The request was throttled (twice)", LoggerService::ERROR);
            } else {
                $this->logMessage(
                    "There was a problem retrieving package labels for :".$shipmentId,
                    LoggerService::ERROR
                );
            }
            $this->debugException($e);
            return null;
        }

        /** @var \FBAInboundServiceMWS_Model_GetPackageLabelsResult $result */
        $result = $response->getGetPackageLabelsResult();
        /** @var \FBAInboundServiceMWS_Model_TransportDocument $transportDoc */
        $transportDoc = $result->getTransportDocument();

        $filePath = tempnam('/tmp', 'package_labels_') . '.zip';
        file_put_contents($filePath, base64_decode($transportDoc->getPdfDocument()));

        return $filePath;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    protected function logMessage($message, $level, $context = [])
    {
        if ($this->logger) {
            // Use the internal logger for logging.
            $this->logger->log($level, $message, $context);
        } else {
            LoggerService::logMessage($message, $level, $context);
        }
    }

    private function debugException(\FBAInboundServiceMWS_Exception $e) {
        $this->logMessage(
            "Exception details:".
            "\nCode:    ".$e->getErrorCode().
            "\nError:   ".$e->getErrorMessage().
            "\nMessage: ".$e->getMessage().
            "\nXML:     ".$e->getXML().
            "\nHeaders: ".$e->getResponseHeaderMetadata()
            ,
            LoggerService::ERROR
        );
    }
}
