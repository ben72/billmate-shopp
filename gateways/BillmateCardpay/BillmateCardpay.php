<?php
/**
 * Billmate Cardpay
 * @class BillmateCardpay
 *
 * @author Combain Mobile AB
 * @version 1.0
 * @package Shopp
 * @since 1.0
 * @subpackage BillmateCardpay
 *
 * $Id: BillmateCardpay.php $
 **/
 
require_once( dirname( SHOPP_GATEWAYS )."/BillmateCore/commonfunctions.php");
require_once dirname( SHOPP_GATEWAYS ).'/BillmateCore/BillMate.php';
include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpc.inc");
include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpcs.inc");
error_reporting(E_ALL);
ini_set('display_errors', 1);
load_plugin_textdomain('shopp-billmate-cardpay', FALSE, dirname(plugin_basename(__FILE__)).'/languages/');
class BillmateCardpay extends GatewayFramework implements GatewayModule {

	var $secure = false;

	var $testurl = 'https://cardpay.billmate.se/pay/test';
	var $liveurl = 'https://cardpay.billmate.se/pay';

	function __construct () {
		parent::__construct();
		add_filter('shopp_checkout_submit_button',array(&$this,'submit'),10,3);
		if( version_compare(SHOPP_VERSION, '1.1.9', '>')){
			add_action('shopp_billmatecardpay_sale',array(&$this,'auth')); // Process sales as auth-only
			add_action('shopp_billmatecardpay_auth',array(&$this,'auth'));
			add_action('shopp_billmatecardpay_capture',array(&$this,'capture'));
			add_action('shopp_billmatecardpay_refund',array(&$this,'refund'));
			add_action('shopp_billmatecardpay_void',array(&$this,'void'));
		}
	
		add_action('shopp_order_success', array(&$this,'success') );
	}
	function auth ($Event) {
		$Order = $this->Order;
		$OrderTotals = $Order->Cart->Totals;
		$Billing = $Order->Billing;
		$Paymethod = $Order->paymethod();

		shopp_add_order_event($Event->order,'authed',array(
			'txnid' => time(),
			'amount' => $OrderTotals->total,
			'fees' => 0,
			'gateway' => $Paymethod->processor,
			'paymethod' => $Paymethod->label,
			'paytype' => $Billing->cardtype,
			'payid' => $Billing->card
		));
	}

	function capture ($Event) {
		shopp_add_order_event($Event->order,'captured',array(
			'txnid' => time(),			// Transaction ID of the CAPTURE event
			'amount' => $Event->amount,	// Amount captured
			'fees' => 0,
			'gateway' => $this->module	// Gateway handler name (module name from @subpackage)
		));
	}

	function refund ($Event) {
		shopp_add_order_event($Event->order,'refunded',array(
			'txnid' => time(),			// Transaction ID for the REFUND event
			'amount' => $Event->amount,	// Amount refunded
			'gateway' => $this->module	// Gateway handler name (module name from @subpackage)
		));
	}
	function void(){
		shopp_add_order_event($Event->order,'voided',array(
			'txnorigin' => $Event->txnid,	// Original transaction ID (txnid of original Purchase record)
			'txnid' => time(),				// Transaction ID for the VOID event
			'gateway' => $this->module		// Gateway handler name (module name from @subpackage)
		));	
	}

	function actions () {
		add_action('shopp_init_confirmation',array(&$this,'confirmation'));

		add_action('shopp_process_checkout', array(&$this,'checkout'),9);
		add_action('shopp_remote_payment',array(&$this,'returned'));
		add_action('shopp_process_order',array(&$this,'process'));
	}

	function confirmation () {
		add_filter('shopp_confirm_url',array(&$this,'url'));
		add_filter('shopp_confirm_form',array(&$this,'form'));
	}

	/* Handle the checkout form */
	function checkout () {
		if( version_compare(SHOPP_VERSION, '1.1.9', '<=')){
			$this->Order->Billing->cardtype = "BillmateCardpay";
			$this->Order->confirm = true;
			$this->Order->capture = $this->settings['authentication_method'] == 'sale' ? 'YES': 'NO';
		} else {
			$Order = ShoppOrder();
			$Order->Billing->cardtype = 'BillmateCardpay';
			$Order->capture = $this->settings['authentication_method'] == 'sale' ? 'YES': 'NO';
			$Order->confirm = true;
		}
	}

	function url ($url) {
		if ($this->settings['testmode'] == "on")
			return $this->testurl;
		else return $this->liveurl;
	}

	function submit ($tag=false,$options=array(),$attrs=array()) {
		$tag[$this->settings['label']] =  '<span class="billmate_cardpay"><span class="col2" style="width:134px"><img src="'.SHOPP_PLUGINURI.'/gateways'.'/'.($this->module).'/bm_kort_l.png"/></span><span>'.__(' Visa & MasterCard','shopp-billmate-cardpay').'</span><input type="image" name="process" src="'.SHOPP_PLUGINURI.'/gateways'.'/'.($this->module).'/betala_kort_knapp.gif" id="checkout-button" '.inputattrs($options,$attrs).' /></span><style type="text/css">
.billmate_cardpay b,.billmate_cardpay span{
    color:#888888!important
}
.billmate_cardpay  {
    float: right;
    width: 68.3%;
    text-align:left;
    font-family:"Helvetica Neue",Arial,Helvetica,"Nimbus Sans L",sans-serif;
}
.billmate_cardpay span{
    float: left;
    padding-right: 33px;
    width: 170px;
		}
.billmate_cardpay img{
    border:0px none!important;
}
.billmate_cardpay span{
font-size:14px;
}
.billmate_cardpay .checkout-button{
clear: right;
float: right;
}
		</style>
<script type="text/javascript">
jQuery(document).ready(function(){
    setTimeout(function(){
        var maxBillmateCardPay = 0;
        jQuery(".shopp.shipmethod").each(function(){
            var val = parseInt( jQuery(this).parent().find("strong").html().replace(",00&nbsp;kr",""));
            if(val > maxBillmateCardPay && jQuery(this).attr("checked")!= "checked"  ){
                jQuery(".shopp.shipmethod").removeAttr("checked");
                jQuery(this).attr("checked","checked");
                jQuery(this).trigger("change");
            }
        });
    },2000);
});
</script>';
		return $tag;
	}

	/**
	 * form()
	 * Builds a hidden form to submit to BillmateCardpay when confirming the order for processing */
	function form ($form) {
		global $Shopp;
		$Order = $this->Order;

		$base = $Shopp->Settings->get('base_operations');
		$lang = explode("_", strtoupper(WPLANG));
		
		$_ = array();
		$_['merchant_id']   = $this->settings['merchantid'];
		$_['currency']      = $base['currency']['code'];
		$_['order_id']      = $this->txnid();
        $_['amount']        = round($Order->Cart->Totals->total,2)*100;
        $secret             = substr( $this->settings['cardpaysecret'],0, 12);
        $_['callback_url']  = $callback_url = 'http://api.billmate.se/callback.php';
		$_['accept_url']    = shoppurl(array('rmtpay'=>'true'),'checkout');
		$_['do_3d_secure']  = $this->settings['do_3d_secure'];
		$_['prompt_name_entry'] = $this->settings['prompt_name_entry'];
		$_['cancel_url']    = shoppurl(false,'cart');
		$_['language']      = $lang[1];
		$_['return_method'] = 'GET';
		$_['pay_method']    = 'CARD';
		$_['capture_now']   = $this->Order->capture;
        $mac_str = $_['accept_url'] . $_['amount'] . $_['callback_url'] . $_['cancel_url'] . $_['capture_now'] . $_['currency'] . $_['do_3d_secure'] . $_['language'] . $_['merchant_id'] . $_['order_id'] . $_['pay_method'] . $_['prompt_name_entry'] . $_['return_method'] . $secret;
        
        $mac = hash ( "sha256", $mac_str );
		unset($_SESSION['card_invoice_called'], $_SESSION['card_invoice_called_inv']);
		$this->billmate_transaction( true );
		$_['mac']					= $mac;

        echo <<<EOD
<style type="text/css">

a.button {
    background: none repeat scroll 0 0 #1189B7;
    border: medium none;
    border-radius: 4px 4px 4px 4px;
    color: #FFFFFF;
    display: block;
    text-decoration:none;
    font: bold 0.875em/1.2 "Helvetica Neue",Helvetica,sans-serif;
    margin: 0.714286em 0;
    padding: 0.857143em 1.07143em;
    cursor:pointer;
    float:right;
}

</style>
<script type="text/javascript">
    jQuery(document).ready(function(){
        var link = jQuery("<a onclick=\"document.getElementById('checkout').submit();\" class='button'>Confirm Order</a>");
        jQuery("#confirm-button").after(link);
        jQuery("#confirm-button").remove();
    });
var maxBillmate = 0;
/*jQuery('.shopp.shipmethod').each(function(){
    var val = parseInt( jQuery(this).parent().find('strong').html().replace(',00&nbsp;kr',''));
    if(val > maxBillmate ){
        jQuery('.shopp.shipmethod').removeAttr('checked');
        jQuery(this).attr('checked','checked');
        jQuery(this).trigger('change');
    }
});*/
</script>
EOD;
		return $this->format($_);
	}

	function process () {
		global $Shopp;
		$Shopp->Order->transaction($Shopp->Order->trans_id,$Shopp->Order->billmatestatus);
	}
	function success($Purchase){
		if(  $Purchase->id ){
			
			$pno = '';
			
			$eid  = (int)$this->settings['merchantid'] ;
			$key = $this->settings['cardpaysecret'];


			$ssl = true;
			$debug = false;
			$k = new BillMate($eid,$key,$ssl,$debug);
			$rno = $this->Order->billmateId;
			$k->UpdateOrderNo($rno, $Purchase->id);
		}
	}
	function returned () {

		global $Shopp;
		if(empty($_POST)) $_POST = $_GET;
        if(empty($_POST)){
           shop_redirect(shoppurl(false, 'checkout'));
           return;
        }
		if( $_POST['status'] != 0){
		    new ShoppError( __("Error Recieved from Billmate Cardpay:",'shopp-billmate-cardpay').$_POST['error_message'],2);
			shopp_redirect(shoppurl(false,'checkout',false));
			return false;
		}

		// Check for unique transaction id
		$Purchase = new Purchase($_POST['trans_id'],'txnid');
        $Shopp->Order->trans_id = $_POST['trans_id'];
        $Shopp->Order->billmatestatus = 'Success';

		$this->billmate_transaction();
		if (!empty($Purchase->id)) {
			$Shopp->Purchase = $Purchase;
			$Shopp->Order->purchase = $Purchase->id;
			shopp_redirect(shoppurl(false,'thanks',false));
			return false;
		}
		// Run order processing
		do_action('shopp_process_order');
	}

	function send ($message) {
		$url = $this->liveurl;
		if ($this->settings['testmode'] == "on") $url = $this->testurl;
		return parent::send($message,$url);
	}
    function billmate_transaction($add_order = false){
		global $Shopp;

		$Shopping = &$Shopp->Shopping;
		$Order = &$Shopp->Order;
		if(empty($_POST)) $_POST = $_GET;
				
		$pno = '';
		
        $eid  = (int)$this->settings['merchantid'] ;
        $key = $this->settings['cardpaysecret'];


		$ssl = true;
		$debug = false;
		$k = new BillMate($eid,$key,$ssl,$debug);

		$Customer = $this->Order->Customer;
		$Billing = $this->Order->Billing;
		$Shipping = $this->Order->Shipping;
		$country = $zone = $locale = $global = false;
        $country = $Billing->country;

		$country_to_currency = array(
			'NO' => 'NOK',
			'SE' => 'SEK',
			'FI' => 'EUR',
			'DK' => 'DKK',
			'DE' => 'EUR',
			'NL' => 'EUR',
		);

		$ship_address = $bill_address = array();
		$countries = Lookup::countries();

        //$countryData = BillmateCountry::getCountryData($Shipping->country);
		$countryData = BillmateCountry::getSwedenData();
	    $ship_address = array(
		    'email'           => $Customer->email,
		    'telno'           => $Customer->phone,
		    'cellno'          => '',
		    'fname'           => $Customer->firstname,
		    'lname'           => $Customer->lastname,
		    'company'         => $Customer->company,
		    'careof'          => '',
		    'street'          => $Shipping->address,
		    'house_number'    => isset($house_no)? $house_no: '',
		    'house_extension' => isset($house_ext)?$house_ext:'',
		    'zip'             => $Shipping->postcode,
		    'city'            => $Shipping->city,
		    'country'         => $countries[$Shipping->country]['name'],
	    );
	    $bill_address = array(
		    'email'           => $Customer->email,
		    'telno'           => $Customer->phone,
		    'cellno'          => '',
		    'fname'           => $Customer->firstname,
		    'lname'           => $Customer->lastname,
		    'company'         => $Customer->company,
		    'careof'          => '',
		    'street'          => $Billing->address,
		    'house_number'    => '',
		    'house_extension' => '',
		    'zip'             => $Billing->postcode,
		    'city'            => $Billing->city,
		    'country'         => $countries[$Billing->country]['name'],
	    );

       foreach($ship_address as $key => $col ){
            $ship_address[$key] = utf8_decode(Encoding::fixUTF8( $col ));
        }
       foreach($bill_address as $key => $col ){
            $bill_address[$key] = utf8_decode(Encoding::fixUTF8( $col ));
        }
        
        extract($countryData);
		
        $goods_list = array();
        $taxrate = 0;



        foreach($this->Order->Cart->contents as $item) {
            // echo links for the items
            $flag = stripos( $item->name, 'billmate fee' ) === false ?
                    (stripos( $item->name, 'billmate invoice fee' ) === false? 0 : 16) : 0; 

            $taxrate = $taxrate == 0 ? $item->taxrate : $taxrate;
	        $goods_list[] = array(
		        'qty'   => (int)$item->quantity,
		        'goods' => array(
			        'artno'    => $item->product,//utf8_decode(Encoding::fixUTF8( $item->name)),
			        'title'    => $item->name,
			        'price'    => round($item->unitprice*100, 0 ), //+$item->unittax
			        'vat'      => (float)round($item->taxrate*100, 0 ),
			        'discount' => 0.0, //round($item->discount, 0 )
			        'flags'    => $flag,
		        )
	        );
        }
		if( $this->Order->Cart->Totals->discount > 0 ){
            $rate = (100+ ($taxrate*100))/100;
            $totalAmt = $this->Order->Cart->Totals->discount;
            $price = $totalAmt-($totalAmt/$rate);
            $discount = $totalAmt - $price;
	        $goods_list[] = array(
		        'qty'   => (int)1,
		        'goods' => array(
			        'artno'    => __('discount','shopp-billmate-cardpay'),
			        'title'    => __('Discount','shopp-billmate-cardpay'),
			        'price'    => -1 * abs( round($this->Order->Cart->Totals->discount*100,0) ),
			        'vat'      => (float)$taxrate*100,
			        'discount' => (float)0,
			        'flags'    => $flag,
		        )
	        );
		}

        if(!empty($this->Order->Cart->Totals->shipping)){
           /* $taxrate = $taxrate * 100;
            $rate = (100+$taxrate)/100;
            $totalAmt = $this->Order->Cart->Totals->shipping;
            $price = $totalAmt-($totalAmt/$rate);
            $shipping = $totalAmt - $price;*/

	        $goods_list[] = array(
		        'qty'   => (int)1,
		        'goods' => array(
			        'artno'    => __('Shipping','shopp-billmate-cardpay'),
			        'title'    => __('Shipping','shopp-billmate-cardpay'),
			        'price'    => round($this->Order->Cart->Totals->shipping*100,0),
			        'vat'      => (float)$taxrate*100,
			        'discount' => 0,
			        'flags'    => 8,
		        )
	        );
        }

		$pclass = -1;

		$lang = explode("_", strtoupper(WPLANG));
		$base = $Shopp->Settings->get('base_operations');
		
		$_ = array();
		
		$transaction = array(
			"order1"=>(string)$this->txnid(),
			"comment"=>(string)"",
			"flags"=>0,
			"reference"=>"",
			"reference_code"=>"",
			"currency"=> $base['currency']['code'], //$currency,
			"country"=>209,
			"language"=>$lang[0], //$language,
			"pclass"=>$pclass,
			"shipInfo"=>array("delay_adjust"=>"1"),
			"travelInfo"=>array(),
			"incomeInfo"=>array(),
			"bankInfo"=>array(),
			"sid"=>array("time"=>microtime(true)),
			"extraInfo"=>array(array("cust_no"=>(string)$Customer->id,"creditcard_data"=>$_POST))
		);

		if(!empty($this->Order->capture) && $this->Order->capture == 'YES') $transaction["extraInfo"][0]["status"] = 'Paid';
		
		if( $add_order ){
			return $k->AddOrder($pno,$bill_address,$ship_address,$goods_list,$transaction);
		}
		
		if(!isset($_SESSION['card_invoice_called']) || $_SESSION['card_invoice_called'] == false){ 
			$result1 = $k->AddInvoice($pno,$bill_address,$ship_address,$goods_list,$transaction);
		}else{
			$result1[0] = $_SESSION['card_invoice_called_inv'];
		}
		if(!is_array($result1))
		{   
	        new ShoppError( __('Unable to process billmate try again <br/>Error:', 'shopp-billmate-cardpay').utf8_encode($result1), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
            die;
		}else
		$this->Order->billmateId = $result1[0];
    }
	function settings () {
		$this->ui->text(0,array(
			'name' => 'merchantid',
			'value' => $this->settings['merchantid'],
			'size' => 7,
			'label' => __('Enter your Merchant ID.','shopp-billmate-cardpay')
		));

		$this->ui->text(1,array(
			'name' => 'cardpaysecret',
			'value' => $this->settings['cardpaysecret'],
			'size' => 30,
			'label' => __('Enter your Secret.','shopp-billmate-cardpay')
		));

		$this->ui->checkbox(1,array(
			'name' => 'testmode',
			'label' => __('Enable test mode','shopp-billmate-cardpay'),
			'checked' => $this->settings['testmode']
		));

		$this->ui->menu(1,array(
			'name' => 'authentication_method',
			'label' => __('Authentication Method','shopp-billmate-cardpay'),
			'selected' => $this->settings['authentication_method']
		),array(
			'authentication'=>__('Authentication','shopp-billmate-cardpay'),
			'sale'=>__('Sale','shopp-billmate-cardpay'),
		));
		
		$this->ui->menu(1,array(
			'name' => 'do_3d_secure',
			'label' => __('Enable 3D Secure','shopp-billmate-cardpay'),
			'selected' => empty($this->settings['do_3d_secure'])?'YES': $this->settings['do_3d_secure'],
		),array(
			'YES'=>__('Yes','shopp-billmate-cardpay'),
			'NO'=>__('No','shopp-billmate-cardpay'),
		));
		
		$this->ui->menu(1,array(
			'name' => 'prompt_name_entry',
			'label' => __('Enable Display Name','shopp-billmate-cardpay'),
			'selected' => empty($this->settings['prompt_name_entry'])?'NO': $this->settings['prompt_name_entry'],
		),array(
			'YES'=>__('Yes','shopp-billmate-cardpay'),
			'NO'=>__('No','shopp-billmate-cardpay'),
		));
	}

} // END class BillmateCardpay

?>
