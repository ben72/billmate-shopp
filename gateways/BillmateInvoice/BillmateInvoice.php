<?php
/**
 * Billmate Invoice
 * @class BillmateInvoice
 *
 * @author Combain Mobile AB
 * @version 1.0
 * @copyright Combain Mobile AB 2013 
 * @package Shopp
 * @subpackage BillmateInvoice
 * @since 1.0
 *
 * $Id: BillmateInvoice.php 1390 2010-09-27 18:43:12Z jdillick $
 **/
include_once( SHOPP_ADDONS."/BillmateCore/lib/utf8.php");
require_once( SHOPP_ADDONS."/BillmateCore/commonfunctions.php");
load_plugin_textdomain('shopp-billmate-invoice', FALSE, dirname(plugin_basename(__FILE__)).'/languages/');

class BillmateInvoice extends GatewayFramework implements GatewayModule {

	var $secure = false;
	function __construct () {
		parent::__construct();
		
		
		add_filter('shopp_checkout_submit_button',array(&$this,'submit'),10,3);
		if( version_compare(SHOPP_VERSION, '1.1.9', '>')){
			add_action('shopp_billmateinvoice_sale',array(&$this,'auth')); // Process sales as auth-only
			add_action('shopp_billmateinvoice_auth',array(&$this,'auth'));
			add_action('shopp_billmateinvoice_capture',array(&$this,'capture'));
			add_action('shopp_billmateinvoice_refund',array(&$this,'refund'));
			add_action('shopp_billmateinvoice_void',array(&$this,'void'));
		}
		add_action('wp_head', array(&$this, 'billmate_load_styles'), 6 );
    	add_action( 'wp_enqueue_scripts', array(&$this, 'billmate_load_scripts'), 6 );
		add_action('shopp_order_success', array(&$this,'success') );
	}
	function success($Purchase){
		if(  $Purchase->id ){
			require_once SHOPP_ADDONS.'/BillmateCore/BillMate.php';
			include_once(SHOPP_ADDONS."/BillmateCore/lib/xmlrpc.inc");
			include_once(SHOPP_ADDONS."/BillmateCore/lib/xmlrpcs.inc");
			
			$pno = '';
			
			$eid  = (int)$this->settings['merchantid'] ;
			$key = $this->settings['secretkey'];


			$ssl = true;
			$debug = false;
			$k = new BillMate($eid,$key,$ssl,$debug);
			$rno = $this->Order->billmateId;
			$k->UpdateOrderNo($rno, $Purchase->id);
		}
	}

	function actions () {
		add_action('shopp_init_confirmation',array(&$this,'confirmation'));
		add_action('shopp_process_checkout', array(&$this,'checkout'),9);
		add_action('shopp_process_order',array(&$this,'process'));
		
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
	function billmate_load_scripts(){
		wp_register_script( 'billmate-billmatepopup-js', plugins_url( '/js/billmatepopup.js',__FILE__ ), array(), false , true );
		wp_enqueue_script( 'billmate-billmatepopup-js' );
	}
    function billmate_load_styles(){
	    //	echo '<link href="'.plugins_url( 'css/colorbox.css', __FILE__ ).'" rel="stylesheet" />';
    }
	function confirmation () {
	    global $Shopp;
		$Shopping = &$Shopp->Shopping;
		$Order = &$Shopp->Order;

		require_once SHOPP_ADDONS.'/BillmateCore/BillMate.php';
		include_once(SHOPP_ADDONS."/BillmateCore/lib/xmlrpc.inc");
		include_once(SHOPP_ADDONS."/BillmateCore/lib/xmlrpcs.inc");
		
		$pno = $this->Order->pno;

		$phone = $this->Order->billmatephone;
		
        $eid  = (int)$this->settings['eid'] ;
        $key = $this->settings['secretkey'];

		$ssl = true;
		$debug = false;
		$k = new BillMate($eid,$key,$ssl,$debug, $this->settings['testmode'] == 'on');

		$Customer = $this->Order->Customer;
		$Billing = $this->Order->Billing;
		$Shipping = $this->Order->Shipping;
		$country = $zone = $locale = $global = false;
        $country = $Billing->country;
		
		if( !in_array($country,$this->settings['avail_country'])){
			new ShoppError( __('Billmate Invoice not available in selected country country code', 'shopp-billmate-invoice').'('.$country.')', 2);
			echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
			die;
		}

		$country_to_currency = array(
			'NO' => 'NOK',
			'SE' => 'SEK',
			'FI' => 'EUR',
			'DK' => 'DKK',
			'DE' => 'EUR',
			'NL' => 'EUR',
		);
		try {
			$addr = $k->GetAddress($pno);

			if( empty( $addr[0] ) ){
		        new ShoppError( __('Invalid personal number', 'shopp-billmate-invoice'), 2);
		        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
                die;
			}
			foreach( $addr[0] as $key => $col ){
				$addr[0][$key] = utf8_encode($col);
			}

			if(isset($addr['error'])){
		        new ShoppError( __('Invalid personal number', 'shopp-billmate-invoice'), 2);
		        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
                die;
            }
		} catch(Exception $e) {
	        new ShoppError( __('Invalid personal number', 'shopp-billmate-invoice'), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
            die;
		}
  		$bo = new StdClass();
		$bo->name = "Billmate Invoice Feee";
		$bo->unitprice = 122;

		if( !empty($this->settings['invoice_fee'])){
            $Product = new Product($this->settings['invoice_fee']);
            $price   = new Price($this->settings['invoice_fee'], 'product');
            $NewItem = new Item($Product,$Price,array(),array(),false);
            if(empty($this->Order->feeadded) || !$this->Order->feeadded) {
        		$Shopp->Shopping->data->Order->Cart->add(1,$Product, $price->id );
        		$Shopp->Shopping->data->Order->Cart->totals();
                $this->Order->feeadded = true;
            }
        }
		
    	$Customer = $this->Order->Customer;
		$Billing =  $Shopp->Shopping->data->Order->Billing;
		$Shipping = $Shopp->Shopping->data->Order->Shipping;
        foreach($addr[0] as $key => $col ){
            $addr[0][$key] = (Encoding::fixUTF8( $col ));
        }
        
		$fullname = $Customer->firstname.' '.$Customer->lastname.' '.$Customer->company;
		
        $firstArr = explode(' ', $Customer->firstname);
        $lastArr  = explode(' ', $Customer->lastname);
        
		$apiName  = $addr[0][0].' '.$addr[0][1];
		
        if( empty( $addr[0][0] ) ){
            $apifirst = $firstArr;
            $apilast  = $lastArr ;
        }else {
            $apifirst = explode(' ', $addr[0][0] );
            $apilast  = explode(' ', $addr[0][1] );
        }

        $matchedFirst = array_intersect($apifirst, $firstArr );
        $matchedLast  = array_intersect($apilast, $lastArr );
        $apiMatchedName   = !empty($matchedFirst) && !empty($matchedLast);

		$addressNotMatched = !isEqual($addr[0][2], $Billing->address ) ||
		    !isEqual($addr[0][3], $Billing->postcode) || 
		    !isEqual($addr[0][4], $Billing->city) || 
		    !isEqual($addr[0][5], BillmateCountry::fromCode(strtoupper($Billing->country)));
		
        $shippingAndBilling =  !$apiMatchedName ||
		    !isEqual($Shipping->address, $Billing->address ) ||
		    !isEqual($Shipping->postcode, $Billing->postcode) || 
		    !isEqual($Shipping->city, $Billing->city) || 
		    !isEqual($Shipping->country, $Billing->country) ;
        if( $addressNotMatched || $shippingAndBilling ){
            if( empty($this->Order->overritedefaultaddress) || !$this->Order->overritedefaultaddress ){
	            $html = '<p style="margin:0px!important;"><b>'.__('Correct Address is :','shopp-billmate-invoice').': </b></p>'.($addr[0][0]).' '.$addr[0][1].'<br>'.$addr[0][2].'<br>'.$addr[0][3].' '.$addr[0][4].'<div style="padding: 17px 0px;"> <i>'.__('Click Yes to continue with new address, No to choose other payment method','shopp-billmate-invoice').'</i></div> <input style="background:#1DA9E7" type="button" value="'.__('Yes','shopp-billmate-invoice').'" onclick="updateAddress();" class="button"/> <input style="background:#1DA9E7" type="button" value="'.__('No','shopp-billmate-invoice').'" onclick="closefunc(this);window.location.reload();" class="button" style="float:right" />';
	            $code = '<style type="text/css">
.checkout-heading {
    background: none repeat scroll 0 0 #F8F8F8!important;
    border: 1px solid #DBDEE1!important;
    color: #555555!important;
    font-size: 13px!important;
    font-weight: bold!important;
    margin-bottom: 15px!important;
    padding: 8px!important;
}
#cboxClose{
 display:none!important;
 visibility:hidden!important;
}
.button:hover{
    background:#444444!important;
}
#divOverlay *{
	text-shadow:none!important;
}
#divOverlay table td, #divOverlay table th{
	padding:0px!important;
}
#divOverlay table td, #divOverlay table th, #divOverlay table{
	border:0px!important;
}

.button {
    border: 0 none!important;
    border-radius: 8px!important;
    box-shadow: 2px 2px 2px 1px #EAEAEA!important;
    color: #FFFFFF!important;
    cursor: pointer!important;
    font-family: arial!important;
    font-size: 14px!important;
    font-weight: bold!important;
    padding: 3px 17px!important;
}
#cboxContent{
    margin:0px!important;
}
	            </style><script type="text/javascript">
	            jQuery(document).ready(function(){
    	            jQuery("#billmatephone").attr("checked","checked");
                    jQuery("select[name=paymethod]").removeAttr("selected");
                    jQuery("select[name=paymethod] option[value^=billmate-invoice]").attr("selected","selected");
                    jQuery("select[name=paymethod] option[value^=billmate-faktura]").attr("selected","selected");
                    jQuery("select[name=paymethod]").trigger("change");
	            });
	            function updateAddress(){
    	            jQuery("select[name=paymethod]").after("<input type=\'hidden\' name=\'geturl\' value=\'true\'/>");
    	            jQuery("#checkout").submit();
	            }
	  jQuery(document).ready(function(){
		modalWin.ShowMessage(\''.$html.'\',300,500,\''.__('Your Billing address is wrong.','shopp-billmate-invoice').'\');
	  });
	  </script>';
	            
                new ShoppError( $code, 2);
		        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
                die;
	        }else{
				if( empty( $addr[0][0] ) ){
					$Shopp->Shopping->data->Order->Customer->company = $addr[0][1];
				}else{
					$Shopp->Shopping->data->Order->Customer->firstname = $addr[0][0];
					$Shopp->Shopping->data->Order->Customer->lastname = $addr[0][1];
					$Shopp->Shopping->data->Order->Customer->company = '';
				}
                $Shipping->address  = $addr[0][2];
                $Shipping->postcode = $addr[0][3];
                $Shipping->city     = $addr[0][4];
                $Shipping->country  = BillmateCountry::getCode($addr[0][5]);

                $Billing->address  = $addr[0][2];
                $Billing->postcode = $addr[0][3];
                $Billing->city     = $addr[0][4];
                $Billing->country  = BillmateCountry::getCode($addr[0][5]);
                
                $Shopp->Shopping->data->Order->Billing  =  $Billing ;
                $Shopp->Shopping->data->Order->Shipping =  $Shipping ;
	        }
        }
		return false;
	}

	/* Handle the checkout form */
	function checkout () {
        $this->isPermitted();

        if(empty($_POST['billmate']['pno'])){
	        new ShoppError( __('Please enter personal number', 'shopp-billmate-invoice'), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
            die;
        }
		if( version_compare(SHOPP_VERSION, '1.1.9', '<=')){
			$this->Order->Billing->cardtype = "Billmate Invoice";
			$this->Order->confirm = true;
			$this->Order->pno = $_POST['billmate']['pno'];
			$this->Order->billmatephone = $_POST['billmate']['phone'];
			$this->Order->overritedefaultaddress = !empty($_POST['geturl'] ) ? true : false;
		} else {
			$Order = ShoppOrder();
			$Order->Billing->cardtype = 'Billmate Invoice';
			$Order->pno = $_POST['billmate']['pno'];
			$Order->billmatephone = $_POST['billmate']['phone'];
			$Order->overritedefaultaddress = !empty($_POST['geturl'] ) ? true : false;
			$Order->confirm = true;
		}
	}
	function remove_fee(){
		global $Shopp;
		$cart = $Shopp->Order->Cart;
		if( !empty($this->settings['invoice_fee'])){
			$Product = new Product($this->settings['invoice_fee']);
			$price   = new Price($this->settings['invoice_fee'], 'product');
			$NewItem = new Item($Product,$price->id, false ,array(),array());

			if (($item = $cart->hasitem($NewItem)) !== false) {
				$cart->remove($item);
				$cart->totals();
			}
			$this->Order->feeadded = false;
			unset($this->Order->feeadded);
		}
	}
	function submit ($tag=false,$options=array(),$attrs=array()) {
        $fee = 0;
		$this->remove_fee();
		if( !empty($this->settings['invoice_fee'])){
            $Product = new Product($this->settings['invoice_fee']);
            $price   = new Price($this->settings['invoice_fee'], 'product');
            $fee = round($price->price,2);
        }
?>
<script type="text/javascript">
function sprintf(){var e=/%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuideEfFgG])/g;var t=arguments,n=0,r=t[n++];var i=function(e,t,n,r){if(!n){n=" "}var i=e.length>=t?"":Array(1+t-e.length>>>0).join(n);return r?e+i:i+e};var s=function(e,t,n,r,s,o){var u=r-e.length;if(u>0){if(n||!s){e=i(e,r,o,n)}else{e=e.slice(0,t.length)+i("",u,"0",true)+e.slice(t.length)}}return e};var o=function(e,t,n,r,o,u,a){var f=e>>>0;n=n&&f&&{2:"0b",8:"0",16:"0x"}[t]||"";e=n+i(f.toString(t),u||0,"0",false);return s(e,n,r,o,a)};var u=function(e,t,n,r,i,o){if(r!=null){e=e.slice(0,r)}return s(e,"",t,n,i,o)};var a=function(e,r,a,f,l,c,h){var p;var d;var v;var m;var g;if(e==="%%"){return"%"}var y=false,b="",w=false,E=false,S=" ";var x=a.length;for(var T=0;a&&T<x;T++){switch(a.charAt(T)){case" ":b=" ";break;case"+":b="+";break;case"-":y=true;break;case"'":S=a.charAt(T+1);break;case"0":w=true;break;case"#":E=true;break}}if(!f){f=0}else if(f==="*"){f=+t[n++]}else if(f.charAt(0)=="*"){f=+t[f.slice(1,-1)]}else{f=+f}if(f<0){f=-f;y=true}if(!isFinite(f)){throw new Error("sprintf: (minimum-)width must be finite")}if(!c){c="fFeE".indexOf(h)>-1?6:h==="d"?0:undefined}else if(c==="*"){c=+t[n++]}else if(c.charAt(0)=="*"){c=+t[c.slice(1,-1)]}else{c=+c}g=r?t[r.slice(0,-1)]:t[n++];switch(h){case"s":return u(String(g),y,f,c,w,S);case"c":return u(String.fromCharCode(+g),y,f,c,w);case"b":return o(g,2,E,y,f,c,w);case"o":return o(g,8,E,y,f,c,w);case"x":return o(g,16,E,y,f,c,w);case"X":return o(g,16,E,y,f,c,w).toUpperCase();case"u":return o(g,10,E,y,f,c,w);case"i":case"d":p=+g||0;p=Math.round(p-p%1);d=p<0?"-":b;g=d+i(String(Math.abs(p)),c,"0",false);return s(g,d,y,f,w);case"e":case"E":case"f":case"F":case"g":case"G":p=+g;d=p<0?"-":b;v=["toExponential","toFixed","toPrecision"]["efg".indexOf(h.toLowerCase())];m=["toString","toUpperCase"]["eEfFgG".indexOf(h)%2];g=d+Math.abs(p)[v](c);return s(g,d,y,f,w)[m]();default:return e}};return r.replace(e,a)}
</script>
<?php

		$tag[$this->settings['label']] =  '<span class="billmate_invoice"><span class="col1"><b>'.__('Invoice Information','shopp-billmate-invoice').'</b><input type="text" id="pno" name="billmate[pno]" class="required" value="'.$this->Order->pno.'" /><label for="pno">'.__('Personal Number', 'shopp-billmate-invoice').'</label></span><span class="col2" style="width:134px"><img src="'.content_url().'/shopp-addons/'.($this->module).'/images/bm_faktura_l.png"/></span><span style="width: 178px;height:99px;"><span style="padding: 0 !important;width: 264px;">'.__('Billmate Invoice - Pay in 14 days','shopp-billmate-invoice').'</span><span><a id="terms">'.__('Terms of invoice','shopp-billmate-invoice').'</a></span></span><span style="min-width:425px;max-width: 480px;padding-right:none;"><input type="checkbox" checked="checked" name="billmate[phone]" id="billmatephone" class="required" value="on" style="float: none;"><label for="billmatephone" style="float: right;width:95%" class="confirmlabel">'.__('My email address is correct and can be used for invoicing purposes.', 'shopp-billmate-invoice').'</label></span><input type="image" src="'.content_url().'/shopp-addons/BillmateInvoice/images/betala_knapp.gif" class="checkout-button" value="Submit Order" id="checkout-button" name="process"></span><style type="text/css">
		.billmate_invoice label{
            font-size: 11px!important;
            font-weight: normal!important;
		}
.billmate_invoice b,.billmate_invoice span{
    color:#888888!important
}
.billmate_invoice  {
    float: left;
    width: 100%;
    text-align:left;
    font-family:"Helvetica Neue",Arial,Helvetica,"Nimbus Sans L",sans-serif;
}
.billmate_partpayment a{
    color:blue;
    cursor:pointer;
}
.billmate_invoice input[type=text]{
    margin:0px!important;
    width:168px!important;
}
.billmate_invoice span{
    float: left;
    padding-right: 33px;
    width: 170px;
		}
.billmate_invoice img{
    border:0px none!important;
}
.billmate_invoice span{
font-size:14px;
}
.billmate_invoice a{
    color:blue;
    cursor:pointer;
}
.billmate_invoice .checkout-button{
clear: right;
float: right;
}
		</style><script type="text/javascript">
Object.keys = Object.keys || function(o) {  
    var result = [];  
    for(var name in o) {  
        if (o.hasOwnProperty(name))  
          result.push(name);  
    }  
    return result;  
};

if ( !Array.prototype.forEach ) {
  Array.prototype.forEach = function(fn, scope) {
    for(var i = 0, len = this.length; i < len; ++i) {   
      fn.call(scope, this[i], i, this);
    }
  }
}
if (!String.prototype.trim) {
  String.prototype.trim = function () {
    return this.replace(/^\s+|\s+$/gm, "");
  };
}
		'.$sprintf.';
		var confirmemailText = "'.__('My email address %s is correct and can be used for invoicing purposes.', 'shopp-billmate-invoice').'";
		jQuery(document).ready(function(){
            jQuery.getScript("https://efinance.se/billmate/base.js", function(){
                    window.$=jQuery;
		            jQuery("#terms").Terms("villkor",{invoicefee: '.$fee.'});
            });
		});

jQuery(document).ready(function(){
	jQuery("#email").change(function(){
		jQuery(".confirmlabel").html(sprintf(confirmemailText, jQuery("#email").val().trim()));
	});
	jQuery(".confirmlabel").html(sprintf(confirmemailText, jQuery("#email").val().trim()));
    /*setTimeout(function(){
        var maxBillmateInvoice = 0;
        jQuery(".shopp.shipmethod").each(function(){
            var val = parseInt( jQuery(this).parent().find("strong").html().replace(",00&nbsp;kr",""));
            if(val > maxBillmateInvoice && jQuery(this).attr("checked")!= "checked" ){
                //jQuery(".shopp.shipmethod").removeAttr("checked");
               // jQuery(this).attr("checked","checked");
                jQuery(this).trigger("change");
            }
        });
    },2000);*/
});</script>';

		return $tag;
	}

	function process () {
		global $Shopp;

		$Shopping = &$Shopp->Shopping;
		$Order = &$Shopp->Order;
		
		require_once SHOPP_ADDONS.'/BillmateCore/BillMate.php';
		include_once(SHOPP_ADDONS."/BillmateCore/lib/xmlrpc.inc");
		include_once(SHOPP_ADDONS."/BillmateCore/lib/xmlrpcs.inc");
		
		$pno = $this->Order->pno;
        
		$phone = $this->Order->billmatephone;
		
        $eid  = (int)$this->settings['eid'] ;
        $key = $this->settings['secretkey'];


		$ssl = true;
		$debug = false;
		$k = new BillMate($eid,$key,$ssl,$debug, $this->settings['testmode'] == 'on');

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

        $countryData = BillmateCountry::getCountryData($Shipping->country);
		
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
		    'country'         => $countryData['country'],
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
		    'country'         => $countryData['country'],
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
        if( sizeof( $this->Order->Cart->contents ) <= 1 && !empty($this->settings['invoice_fee'])){
            $Shopp->Shopping->data->Order->Cart->contents = array();
	        new ShoppError( __('Cart is empty', 'shopp-billmate-invoice'), 2);
	        $this->Order->feeadded = false;
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'cart').'";</script>';
	        die;            
        }
        foreach($this->Order->Cart->contents as $item) {
            // echo links for the items
           
            $flag = stripos( $item->name, 'billmate fee' ) === false ?
                    (stripos( $item->name, 'billmate invoice fee' ) === false? 0 : 16) : 0; 

            $flag = stripos( $item->name, 'billmate fee' ) === false ?
                    (stripos( $item->name, 'billmate invoice fee' ) === false? 0 : 16) : 0; 

            $taxrate = $taxrate == 0 ? $item->taxrate : $taxrate;
	        $goods_list[] = array(
		        'qty'   => (int)$item->quantity,
		        'goods' => array(
			        'artno'    => $item->product,
			        'title'    => $item->name,
			        'price'    => round($item->unitprice*100,0),
			        'vat'      => round($item->taxrate*100,0),
			        'discount' => 0.0,
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
			        'artno'    => __('discount','shopp-billmate-invoice'),
			        'title'    => __('Discount','shopp-billmate-invoice'),
			        'price'    => -1 * abs( round($this->Order->Cart->Totals->discount*100,0) ),
			        'vat'      =>  $taxrate*100,
			        'discount' => 0,
			        'flags'    => $flag,
		        )
	        );
		}

        if(!empty($this->Order->Cart->Totals->shipping)){
            $totalAmt = $this->Order->Cart->Totals->shipping;

	        $goods_list[] = array(
		        'qty'   => (int)1,

		        'goods' => array(
			        'artno'    => __('Shipping','shopp-billmate-invoice'),
			        'title'    => __('Shipping','shopp-billmate-invoice'),
			        'price'    => round($totalAmt*100,0),
			        'vat'      => $taxrate*100,
			        'discount' => 0,
			        'flags'    => 8,
		        )
	        );
        }
		$pclass = -1;

		$transaction = array(
			"order1"=>(string)$this->txnid(),
			"comment"=>(string)"",
			"flags"=>0,
			"reference"=>"",
			"reference_code"=>"",
			"currency"=>$currency,
			"country"=>$country,
			"language"=>$language,
			"pclass"=>$pclass,
			"shipInfo"=>array("delay_adjust"=>"1"),
			"travelInfo"=>array(),
			"incomeInfo"=>array(),
			"bankInfo"=>array(),
			"sid"=>array("time"=>microtime(true)),
			"extraInfo"=>array(array("cust_no"=>(string)$order_info['customer_id']))
		);
		$result1 = $k->AddInvoice($pno,$bill_address,$ship_address,$goods_list,$transaction);
		//shopp_rmv_cart_item($this->settings['invoice_fee']);
		if(!is_array($result1))
		{   
			$this->remove_fee();
/*
			if( !empty($this->settings['invoice_fee'])){
				$Shopp->Shopping->data->Order->Cart->remove(sizeof( $this->Order->Cart->contents )-1);
				$Shopp->Shopping->data->Order->Cart->totals();
				$this->Order->feeadded = false;
				unset($this->Order->feeadded);
			}
			*/
	        new ShoppError( __('Unable to process billmate try again <br/>Error:', 'shopp-billmate-invoice').utf8_encode($result1), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
            die;
		}else
		$this->Order->billmateId = $result1[0];
		
 		$this->Order->pno = '';
		$this->Order->billmatephone = '';
        $this->Order->feeadded = false;
		$this->Order->transaction($this->txnid());
	    return true;
	}
	function isPermitted(){
	    if(!$this->isConfigured()){
	        new  ShoppError( __('Billmate Invoice is not cofigured', 'shopp-billmate-invoice'), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
	    }
	}
	function isConfigured(){
	    return !empty($this->settings['eid']) && !empty($this->settings['secretkey']);
	}
	
	function settings () {
		$available = array(
			'SE' =>__( 'Sweden','Shopp'),
			'FI' =>__('Finland', 'Shopp'),
			'DK' =>__('Danmark', 'Shopp'),
			'NO' =>__( 'Norway' ,'Shopp')
		);

		$this->ui->text(0,array(
			'name' => 'eid',
			'value' => $this->settings['eid'],
			'size' => 7,
			'label' => __('Enter your EID.','shopp-billmate-invoice')
		));

		$this->ui->text(0,array(
			'name' => 'secretkey',
			'value' => $this->settings['secretkey'],
			'size' => 40,
			'label' => __('Enter your Billmate secret key.','shopp-billmate-invoice')
		));

		$this->ui->text(1,array(
			'name' => 'invoice_fee',
			'value' => empty($this->settings['invoice_fee'])? 0 :$this->settings['invoice_fee'],
			'size' => 40,
			'label' => __('Enter invoie Fee Product ID.(Please create a product name must be Billmate Invoice fee/Billmate Fee)','shopp-billmate-invoice')
		));
		
		$this->ui->checkbox(1,array(
			'name' => 'testmode',
			'label' => __('Enable test mode','shopp-billmate-invoice'),
			'checked' => $this->settings['testmode']
		));
		
		$this->ui->multimenu(1,array(
			'name' => 'avail_country',
			'label' => __('Billmate Invoice activated for customers in these countries','shopp-billmate-cardpay'),
			'multiselect' => 'multiselect',
			'selected' => $this->settings['avail_country'],
		),$available);


	}

} // END class BillmateInvoice

?>