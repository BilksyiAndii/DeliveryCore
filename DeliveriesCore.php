<?php
namespace Flamix\Post\Newpost\DeliveriesCore;

use \Flamix\Post\Newpost\NewPost;
use \Flamix\Post\Newpost\subscribeHandler;
use \Bitrix\Sale;
use \Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

interface DeliveriesCoreInterface {
    public static function initPost();
    public static function getDataCity($arOrder, $arConfig);
    public static function getPrice($arOrder, $profile, $arConfig);
    public static function getDateDelivery($arOrder, $pofile, $arConfig);
    public static function getPvzList($arOrder, $arConfig);
    public static function deliveryConfig();

}

/**
 * Delivery Core
 *
 * Class DeliveriesCore
 * @package lib\DeliveriesCore
 */
class DeliveriesCore extends NewPost implements DeliveriesCoreInterface
{
    protected static $language  = LANGUAGE_ID;
    //protected static $sid       = 'flamix.np';
    protected static $show_button;
    protected $api_key;
    protected $price;
    protected $weight;
    protected $departure_date;
    protected $currency;

    public static $city_from;
    public static $city_to;
    public static $city_zip;

    //CACHE
    public static $cache_time = 259200;
    public static $cache_city = 1;

    public static $init;

    public function __construct($arOrder)
    {
        $this->parseOrder($arOrder);

        self::$init = $this;

        return $this;
    }

    public static function getInit()
    {
        return self::$init;
    }


    private function parseOrder($arOrder)
    {
        $this->price     = $arOrder['PRICE'];
        self::$city_from = $arOrder['LOCATION_FROM'];
        self::$city_to   = $arOrder['LOCATION_TO'];
        self::$city_zip  = $arOrder['LOCATION_ZIP'];
        $this->weight    = $arOrder['WEIGHT'];

        return $this;
    }


    /**
     * Sets the language in which the information will be received
     *
     * @param $language
     * @return $this
     */
    public function setLanguage($language){
        $this->language = $language;

        return $this;
    }


    /**
     * Gets language
     *
     * @return mixed|string
     */
    public static function getLanguage(){
        return self::$language;
    }


    /**
     * Sets the currency
     *
     * @return $this
     * @throws \Bitrix\Main\LoaderException
     */
    public function setCurrency(){
        \Bitrix\Main\Loader::includeModule('sale');
        $this->currency = \CCurrency::GetBaseCurrency();

        return $this;
    }


    /**
     * Receives currency
     *
     * @return mixed
     */
    public function getCurrency(){
        return $this->currency;
    }

    public static function getLang()
    {
        switch (LANGUAGE_ID) {
            case 'ua':
                return 'ua';
                break;

            case 'en':
                return 'en';
                break;

            default:
                return 'ru';
        }
    }

    /**
     * Delivery service configuration request.
     * @return array
     */
    public static function getDataConfig()
    {
        return self::deliveryConfig();
    }


    /**
     * Get all New Post delivery ids
     *
     * @return array
     */
    public function getAllDeliveryIds()
    {
        $arIds                      = array();
        $arProfiles                 = $this->getProfileNames();
        if(!empty($arProfiles)){
            foreach ($arProfiles as $arProfile) {
                $deliveryIds        = $this->getDeliveryId($arProfile);
                $arIds              = array_merge($arIds,$deliveryIds);
            }
        }

        return $arIds;
    }

    /**
     * Gets the address of the store from the settings of the Online store module
     * @return mixed
     */
    public static function getAddressMagazine(){
        $address_code = \Bitrix\Main\Config\Option::get('sale','location');

        return $address_code;
    }

    /**
     * Getting the name of the city and region
     * @param $arOrder
     * @return mixed
     */
    public static function getCitiesAndRegions($arOrder){
        if(!is_array($arOrder))
            $arCityCodes = $arOrder;
        elseif (!$arOrder["LOCATION_FROM"] && $arOrder["LOCATION_TO"])
            $arCityCodes = $arOrder["LOCATION_TO"];
        else
            $arCityCodes = array($arOrder["LOCATION_FROM"], $arOrder["LOCATION_TO"]);


        \Bitrix\Main\Loader::includeModule('sale');
        $res = \Bitrix\Sale\Location\LocationTable::getList(array(
            'filter' => array(
                '=CODE'                         => $arCityCodes,
                'NAME.LANGUAGE_ID'              => self::$language,
                '=PARENT.NAME.LANGUAGE_ID'      => self::$language,
                '=PARENT.TYPE.NAME.LANGUAGE_ID' => self::$language,
            ),
            'select' => array(
                'EXTERNAL',
                'ZIP'                           => 'EXTERNAL.XML_ID',
                'LNAME'                         => 'NAME.NAME',
                'PARENT.*',
                'CODE'                          => 'CODE',
                'NAME_RU'                       => 'PARENT.NAME.NAME',
                'TYPE_CODE'                     => 'PARENT.TYPE.CODE',
                'TYPE_NAME_RU'                  => 'PARENT.TYPE.NAME.NAME'
            )
        ));
        if($item = $res->fetch()) {;
            $arCities[$item["CODE"]] = array(
                "INDEX"                         => $item["SALE_LOCATION_LOCATION_EXTERNAL_XML_ID"],
                "ZIP"                           => $item["ZIP"],
                "CITY_NAME"                     => $item["LNAME"],
                "CODE"                          => $item["CODE"],
                "REGION_NAME"                   => $item["TYPE_CODE"] == "REGION" ? $item["NAME_RU"] : $item["LNAME"]
            );
        }

        return $arCities;
    }


    public static function getDeliveryByID(){
        if(! \Bitrix\Main\Loader::includeModule('sale')) return false;

        $dS = \CSaleDeliveryHandler::GetBySID(self::$sid, SITE_ID)->Fetch();

        return $dS;
    }

    /**
     * Receives New Post delivery service from admin panel
     *
     * @param bool $skipSite
     * @return bool
     */
    public static function getDeliveries($skipSite = false){
        if(! \Bitrix\Main\Loader::includeModule('sale')) return false;

        $cite = ($skipSite) ? false : SITE_ID;
        if(\COption::GetOptionString("main","~sale_converted_15",'N') == 'Y'){
            $dS = \Bitrix\Sale\Delivery\Services\Table::getList(array(
                'order'  => array('SORT' => 'ASC', 'NAME' => 'ASC'),
                'filter' => array('CODE' => self::$sid)
            ))->Fetch();
        } else
            $dS = \CSaleDeliveryHandler::GetBySID(self::$sid,$cite)->Fetch();

        return $dS;
    }

    public static function getLastWord($transit) {
        if ( $transit < 0 || $transit == 0 )
            return Loc::getMessage('TODAY');

        if ( $transit < 2 )
            return Loc::getMessage('TOMORROW');

        if ( $transit >= 2 && $transit < 5 )
            $last_word = Loc::getMessage('DAY2');
        else
            $last_word = Loc::getMessage('DAY3');

        return $transit . ' ' . $last_word;
    }

    /**
     * Returns delivery service settings
     *
     * @return mixed
     */
    public static function getSettingsDelivery(){
        \Bitrix\Main\Loader::includeModule('sale');

        $dbResult = \CSaleDeliveryHandler::GetList(
            array(
                'SORT' => 'ASC',
                'NAME' => 'ASC'
            ),
            array(
                'SID' => self::$sid
            )
        );

        while ($arResult = $dbResult->GetNext()) {
            $arSettings = $arResult;
        }

        return $arSettings;
    }

    public  function getCurrent($full = false)
    {
        return ($full) ? $this->deliveryInit() : $this->deliveryInit()['SID'];
    }


    /**
     * Retrieves delivery profiles for New Post
     *
     * @return array
     */
    public function getProfileNames(){
        return array_keys(self::getProfiles());
    }

    /**
     * Gets delivery id by profile
     *
     * @param $profile
     * @return array
     */
    public  function getDeliveryId($profile){
        $profiles   = array();
        $label      = self::getCurrent();

        if(\COption::GetOptionString("main","~sale_converted_15",'N') == 'Y'){
            $dTS = \Bitrix\Sale\Delivery\Services\Table::getList(array(
                'order'  => array('SORT' => 'ASC', 'NAME' => 'ASC'),
                'filter' => array('CODE' => $this->sid.':'.$profile)
            ));
            while($dPS = $dTS->Fetch())
                $profiles[] = $dPS['ID'];
        } else
            $profiles = array($label.'_'.$profile);

        return $profiles;
    }

    /**
     * Checks if delivery is active
     *
     * @return bool
     */
    public function isActive(){
        $dS = self::getDelivery();

        return ($dS && $dS['ACTIVE'] == 'Y');
    }

    /**
     * Receives New Post delivery service from admin panel
     *
     * @param bool $skipSite
     * @return bool
     */
    public static function getDelivery($skipSite = false){
        if(! \Bitrix\Main\Loader::includeModule('sale')) return false;

        $cite = ($skipSite) ? false : SITE_ID;
        if(\COption::GetOptionString("main","~sale_converted_15",'N') == 'Y'){
            $dS = \Bitrix\Sale\Delivery\Services\Table::getList(array(
                'order'  => array('SORT' => 'ASC', 'NAME' => 'ASC'),
                'filter' => array('CODE' => self::$sid)
            ))->Fetch();
        } else
            $dS = \CSaleDeliveryHandler::GetBySID(self::$sid,$cite)->Fetch();

        return $dS;
    }

    /**
     * Receives the address of the store from the settings of the Online store module
     *
     * @return mixed
     */
    public function getOptions(){
        $loc_code = \Bitrix\Main\Config\Option::get('sale','location');

        return array(
            'CODE' => $loc_code,
            'ZIP' => \Bitrix\Main\Config\Option::get('sale','location_zip'),
            'INDEX' =>  self::getCitiesAndRegions($loc_code)[$loc_code]['INDEX']
        );
    }

    /**
     * @return array
     * Returns a list of order properties which are the address
     */
    public static function getCodeAddressProps(){
        \Bitrix\Main\Loader::includeModule('sale');

        $tmpGet = \CSaleOrderProps::GetList(array("SORT" => "ASC"),array("IS_ADDRESS"=>"Y"));

        while ($ar_res = $tmpGet->Fetch()) {
            $arCodeProps[] = $ar_res["ID"];
        }

        return $arCodeProps;
    }

    /**
     * We check the consistency of the order data for the delivery profiles
     *
     * @param $arOrder
     * @return bool
     */
    public static function compabilityDelivery($arOrder, $arConfig){
        if($arConfig['API_KEY']['VALUE']) {
            $dlvr_obj = new DeliveriesCore($arOrder);

            $branch_list        = subscribeHandler::getBranchList($arOrder);
            $profiles           = $dlvr_obj->getProfileNames();

            if (!$branch_list) {
                $profile_key = array_search('pickup', $profiles);
                unset($profiles[$profile_key]);
            }

            $data_price = NewPost::getPrice($arOrder, 'courier', $arConfig);
            if (!$data_price) {
                $profile_key = array_search('courier', $profiles);
                unset($profiles[$profile_key]);
            }

            if ($profiles)
                return $profiles;
        }
        return false;
    }


    /**
     * Calculation of the cost and delivery time
     * @param $arOrder
     * @param $arConfig
     */
    public static function Calculator($arOrder, $profile, $arConfig){
        $status        = false;
        $price         = self::getPrice($arOrder, $profile, $arConfig);
        $date_delivery = self::getDateDelivery($arOrder,$profile, $arConfig);

        
        if($price && $date_delivery)
            $status = true;

        /*We put the value 1 in the session to check if the user is in the basket.*/
        $_SESSION[strtoupper(str_replace('.', '_', self::$sid))] = 1;

        if(!$status)
            return array(
                'RESULT'        => 'ERROR',
                'TEXT'          => Loc::getMessage('ERROR_CALCULATE_PRICE')
            );

        return array(
            'RESULT'    => 'OK',
            'VALUE'     => $price,
            'TRANSIT'   => $date_delivery
        );
    }
}