<?php
/**
 * Billmate Partpayment
 * @class BillmatePartpayment
 *
 * @author Jonathan Davis
 * @version 1.1.2
 * @package Shopp
 * @subpackage BillmatePartpayment
 * @since 1.1.5
 *
 * $Id: BillmatePartpayment.php 1390 2010-09-27 18:43:12Z jdillick $
 **/
if(!file_exists( dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/utf8.php" )){
    die("Billmate Core is Required to enable this gateway");
}
include_once( dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/utf8.php");

load_plugin_textdomain('shopp-billmate-partpayment', FALSE, dirname(plugin_basename(__FILE__)).'/languages/');

class BillmatePartpayment extends GatewayFramework implements GatewayModule {
    var $countries = array(209 =>'sweden', 73=> 'finland',59=> 'denmark', 164 => 'norway', 81 => 'germany' );
	var $secure = false;
	function __construct () {
		parent::__construct();
		add_filter('shopp_checkout_submit_button',array(&$this,'submit'),10,3);
		if( version_compare(SHOPP_VERSION, '1.1.9', '>')){
			add_action('shopp_billmatepartpayment_sale',array(&$this,'auth')); // Process sales as auth-only
			add_action('shopp_billmatepartpayment_auth',array(&$this,'auth'));
			add_action('shopp_billmatepartpayment_capture',array(&$this,'capture'));
			add_action('shopp_billmatepartpayment_refund',array(&$this,'refund'));
			add_action('shopp_billmatepartpayment_void',array(&$this,'void'));
		}
		add_action('wp_head', array(&$this, 'billmate_load_styles'), 6 );
    	add_action( 'wp_enqueue_scripts', array(&$this, 'billmate_load_scripts'), 6 );
		add_action('shopp_order_success', array(&$this,'success') );
	}
	function success($Purchase){
		if(  $Purchase->id ){
			require_once dirname( SHOPP_GATEWAYS ).'/BillmateCore/BillMate.php';
			include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpc.inc");
			include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpcs.inc");
			
			$pno = '';
			
			$eid  = (int)$this->settings['merchantid'] ;
			$key = (float)$this->settings['secretkey'];


			$ssl = true;
			$debug = false;
			$k = new BillMate($eid,$key,$ssl,$debug);
			$rno = $this->Order->billmateId;
			$k->UpdateOrderNo($rno, $Purchase->id);
		}
	}

	function actions () {
        add_action('shopp_save_payment_settings', array(&$this, 'fetchpclasses') );
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

	function confirmation () {
	    global $Shopp;

		$Shopping = &$Shopp->Shopping;
		$Order = &$Shopp->Order;

		require_once dirname( SHOPP_GATEWAYS ).'/BillmateCore/BillMate.php';
		include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpc.inc");
		include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpcs.inc");
		
		$pno = $this->Order->partpaymentpno;

		$phone = $this->Order->partpaymentphone;
		
        $eid  = (int)$this->settings['eid'] ;
        $key = (float)$this->settings['secretkey'];

		$ssl = true;
		$debug = false;
		$k = new BillMate($eid,$key,$ssl,$debug, $this->settings['testmode'] == 'on');

		$Customer = $this->Order->Customer;
		$Billing = $this->Order->Billing;
		$Shipping = $this->Order->Shipping;
		$country = $zone = $locale = $global = false;
        $country = $Billing->country;
		
		if( !in_array($country,$this->settings['avail_country'])){
			new ShoppError( __('Billmate Partpayment not available in selected country country code', 'shopp-billmate-partpayment').'('.$country.')', 2);
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
		        new ShoppError( __('Invalid personal number', 'shopp-billmate-partpayment'), 2);
		        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
                die;
			}
			foreach( $addr[0] as $key => $col ){
				$addr[0][$key] = utf8_encode($col);
			}

			if(isset($addr['error'])){
		        new ShoppError( __('Invalid personal number', 'shopp-billmate-partpayment'), 2);
		        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
                die;
            }
		} catch(Exception $e) {
	        new ShoppError( __('Invalid personal number', 'shopp-billmate-partpayment'), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
            die;
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
		    !isEqual($addr[0][5], BillmateCountry::fromCode($Billing->country));

        $shippingAndBilling =  !$apiMatchedName ||
		    !isEqual($Shipping->address, $Billing->address ) ||
		    !isEqual($Shipping->postcode, $Billing->postcode) || 
		    !isEqual($Shipping->city, $Billing->city) || 
		    !isEqual($Shipping->country, $Billing->country) ;
		
		

        if( $addressNotMatched || $shippingAndBilling ){
            if( empty($this->Order->overritedefaultaddress2) || !$this->Order->overritedefaultaddress2 ){
	            $html = '<p><b>'.__('Correct Address is :','shopp-billmate-partpayment').'</b></p>'.($addr[0][0]).' '.$addr[0][1].'<br>'.$addr[0][2].'<br>'.$addr[0][3].' '.$addr[0][4].'<div style="padding: 17px 0px;"> <i>'.__('Click Yes to continue with new address, No to choose other payment method','shopp-billmate-partpayment').'</i></div> <input type="button" value="'.__('Yes','shopp-billmate-partpayment').'" onclick="updateAddress();" class="button"/> <input type="button" value="'.__('No','shopp-billmate-partpayment').'" onclick="closefunc(this)" class="button" style="float:right" />';
	            $code = '<style type="text/css">
.checkout-heading {
    background: none repeat scroll 0 0 #F8F8F8;
    border: 1px solid #DBDEE1;
    color: #555555;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 15px;
    padding: 8px;
}
#cboxClose{
 display:none!important;
 visibility:hidden!important;
}
.button:hover{
    background:#0B6187!important;
}

.button {
    background-color: #1DA9E7;
    border: 0 none;
    border-radius: 8px 8px 8px 8px;
    box-shadow: 2px 2px 2px 1px #EAEAEA;
    color: #FFFFFF;
    cursor: pointer;
    font-family: arial;
    font-size: 14px!important;
    font-weight: bold;
    padding: 3px 17px;
}
#cboxContent{
    margin:0px!important;
}
.billmate_partpayment a{
    color:blue;
    cursor:pointer;
}
	            </style><script type="text/javascript">
	            jQuery(document).ready(function(){
    	            jQuery("#billmatepartphone").attr("checked","checked");
                    jQuery("select[name=paymethod]").removeAttr("selected");
                    jQuery("select[name=paymethod] option[value=billmate-partpayment]").attr("selected","selected");
                    jQuery("select[name=paymethod] option[value=billmate-delbetalning]").attr("selected","selected");
                    jQuery("select[name=paymethod]").trigger("change");
	            });
	            function updateAddress(){
    	            jQuery("select[name=paymethod]").after("<input type=\'hidden\' name=\'geturlpartpayment\' value=\'true\'/>");
    	            jQuery("#checkout").submit();
	            }
	  jQuery(document).ready(function(){
		modalWin.ShowMessage(\''.$html.'\',300,500,\''.__('Your Billing address is wrong.','shopp-billmate-partpayment').'\');
	  });</script>';
	            
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
		return true;
	}
    function correct_lang_billmate(&$item, $index){
        $keys = array('pclassid', 'description','months', 'start_fee','invoice_fee','interest', 'mintotal', 'country', 'Type', 'expiry' );
        $item[1] = utf8_encode($item[1]);
        $item = array_combine( $keys, $item );
        $item['start_fee'] = $item['start_fee'] / 100;
        $item['invoice_fee'] = $item['invoice_fee'] / 100;
        $item['interest'] = $item['interest'] / 100;
        $item['mintotal'] = $item['mintotal'] / 100;
    }
	function fetchpclasses(){
	    
	    $eid    = (int)$this->settings['eid'];
	    $secret = (float)$this->settings['secretkey'];

	    $ssl = true;
	    $debug = false;

		require_once dirname( SHOPP_GATEWAYS ).'/BillmateCore/BillMate.php';
		include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpc.inc");
		include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpcs.inc");

        $k = new BillMate($eid,$secret,$ssl,$debug, $this->settings['testmode'] == 'on');
        $result = array();
        foreach($this->countries as $countryid => $countryname ){
        
            $data = $k->FetchCampaigns(
                BillmateCountry::getCountryData($countryid)
            );
            array_walk($data, array(&$this, 'correct_lang_billmate'));
            $countryCode = strtoupper( BillmateCountry::getCode( $countryid ));
            $result[$countryCode] = $data;  
        }

        if( !sizeof($result ) ){
    	    echo '<div class="wrap shopp"><div class="error"><p>' , _e('Unable to fetch Billmate Pclasses','shopp-billmate-partpayment'),'</p></div></div>';
        }else{
            $pclases = json_encode($result, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
            $fp = fopen(dirname( SHOPP_GATEWAYS ).'/BillmateCore/billmatepclasses.json', 'w+');
            if( !$fp ){
        	    echo '<div class="wrap shopp"><div class="error"><p>' , _e('Unable to open file to write.'.dirname( SHOPP_GATEWAYS ).'/BillmateCore/billmatepclasses.json','shopp-billmate-partpayment'),'</p></div></div>';
        	    return true;
            }
            fwrite($fp, $pclases );
            fclose($fp);    
    	    echo '<div class="wrap shopp"><div class="updated"><p>' , _e('Billmate Plcasses are saved','shopp-billmate-partpayment'),'</p></div></div>';
        }
	}
	function billmate_load_scripts(){
		wp_register_script( 'billmate-billmatepopup-js', plugins_url( '/js/billmatepopup.js',__FILE__ ), array(), false, true );
		wp_enqueue_script( 'billmate-billmatepopup-js' );
	}
    function billmate_load_styles(){
	    //	echo '<link href="'.plugins_url( 'css/colorbox.css', __FILE__ ).'" rel="stylesheet" />';
    }
	/* Handle the checkout form */
	function checkout () {
        $this->isPermitted();

        if(empty($_POST['pclass'])){
	        new ShoppError( __('Please select payment plan', 'shopp-billmate-partpayment'), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
            die;
        }
        if(empty($_POST['billmatepartpayment']['pno'])){
	        new ShoppError( __('Please enter personal number', 'shopp-billmate-partpayment'), 2);
	        echo '<script type="text/javascript">window.location.href="'.shoppurl(false,'checkout').'";</script>';
            die;
        }

		if( version_compare(SHOPP_VERSION, '1.1.9', '<=')){
			$this->Order->Billing->cardtype = "Billmate PartPayment";
			$this->Order->confirm = true;
			$this->Order->partpaymentpno = $_POST['billmatepartpayment']['pno'];
			$this->Order->partpaymentphone = $_POST['billmatepartpayment']['phone'];
			$this->Order->pclass = $_POST['pclass'];
			$this->Order->overritedefaultaddress2 = !empty($_POST['geturlpartpayment'] ) ? true : false;
		} else {
			$Order = ShoppOrder();
			$Order->Billing->cardtype = 'Billmate PartPayment';
			$Order->pno = $_POST['billmate']['pno'];
			$Order->partpaymentpno = $_POST['billmatepartpayment']['pno'];
			$Order->partpaymentphone = $_POST['billmatepartpayment']['phone'];
			$Order->pclass = $_POST['pclass'];
			$Order->overritedefaultaddress2 = !empty($_POST['geturlpartpayment'] ) ? true : false;
			$Order->confirm = true;
		}
		
	}
    private function getLowestPaymentAccount($country) {
        switch ($country) {
            case 'SWE':
            case 'SE':
                $amount = 50.0;
                break;
            case 'NOR':
            case 'NO':
                $amount = 95.0;
                break;
            case 'FIN':
            case 'FI':
                $amount = 8.95;
                break;
            case 'DNK':
            case 'DK':
                $amount = 89.0;
                break;
            case 'DEU':
            case 'DE':
            case 'NLD':
            case 'NL':
                $amount = 6.95;
                break;
            default:
 				$amount = NULL;
                break;
        }

        return $amount;
    }
    function calcPayment($data, $total){
        $payment_option = array();
		foreach ($data as $pclassobj) {                
            $pclass = (array)$pclassobj;
            
			// 0 - Campaign
			// 1 - Account
			// 2 - Special
			// 3 - Fixed
			if (!in_array($pclass['Type'], array(0, 1, 3))) {
				continue;
			}

			if ($pclass['Type'] == 2) {
				$monthly_cost = -1;
			} else {
				if ($total < $pclass['mintotal']) {
					continue;
				}

				if ($pclass['Type'] == 3) {
					continue;
				} else {
					$sum = $total;

					$lowest_payment = $this->getLowestPaymentAccount($Billing->country);
					$monthly_cost = 0;

					$monthly_fee = $pclass['invoice_fee'];
					$start_fee = $pclass['start_fee'];

					$sum += $start_fee;

					$base = ($pclass['Type'] == 1);

					$minimum_payment = ($pclass['Type'] === 1) ? $this->getLowestPaymentAccount($Billing->country) : 0;
 
					if ($pclass['months'] == 0) {
						$payment = $sum;
					} elseif ($pclass['interest'] == 0) {
						$payment = $sum / $pclass['months'];
					} else {
						$interest = $pclass['interest'] / (100.0 * 12);
						$payment = $sum * $interest / (1 - pow((1 + $interest), -$pclass['months']));
					}

					$payment += $monthly_fee;

					$balance = $sum;
					$pay_data = array();

					$months = $pclass['months'];
					
					while (($months != 0) && ($balance > 0.01)) {
						$interest = $balance * $pclass['interest'] / (100.0 * 12);
						$new_balance = $balance + $interest + $monthly_fee;

						if ($minimum_payment >= $new_balance || $payment >= $new_balance) {
							$pay_data[] = $new_balance;
							break;
						}

						$new_payment = max($payment, $minimum_payment);
						
						if ($base) {
							$new_payment = max($new_payment, $balance / 24.0 + $monthly_fee + $interest);
						}

						$balance = $new_balance - $new_payment;
						
						$pay_data[] = $new_payment;
							   
						$months -= 1;
					}

					$monthly_cost = round(isset($pay_data[0]) ? ($pay_data[0]) : 0, 2);

					if ($monthly_cost < 0.01) {
						continue;
					}

					if ($pclass['Type'] == 1 && $monthly_cost < $lowest_payment) {
						$monthly_cost = $lowest_payment;
					}

					if ($pclass['Type'] == 0 && $monthly_cost < $lowest_payment) {
						continue;
					}
				}
			}
			
			$payment_option[$pclass['pclassid']]['pclass_id'] = $pclass['pclassid'];
			$payment_option[$pclass['pclassid']]['title'] = $pclass['description'];
			$payment_option[$pclass['pclassid']]['months'] = $pclass['months'];
			$payment_option[$pclass['pclassid']]['monthly_cost'] = $monthly_cost;
		}
		 return $payment_option;
    }
	function submit ($tag=false,$options=array(),$attrs=array()) {
	    global $Shopp;
	    $Billing = $this->Order->Billing;
		$content = file_get_contents(dirname( SHOPP_GATEWAYS ).'/BillmateCore/billmatepclasses.json');
		$data    = json_decode($content );
		$data2   = array();
		foreach( $data as $key => $col ){
		    $data2[$key] = $this->calcPayment((array)$col,$this->Order->Cart->Totals->total); 
		}
		$countries = array_keys($data2);
?>
<script type="text/javascript">
function sprintf(){var e=/%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuideEfFgG])/g;var t=arguments,n=0,r=t[n++];var i=function(e,t,n,r){if(!n){n=" "}var i=e.length>=t?"":Array(1+t-e.length>>>0).join(n);return r?e+i:i+e};var s=function(e,t,n,r,s,o){var u=r-e.length;if(u>0){if(n||!s){e=i(e,r,o,n)}else{e=e.slice(0,t.length)+i("",u,"0",true)+e.slice(t.length)}}return e};var o=function(e,t,n,r,o,u,a){var f=e>>>0;n=n&&f&&{2:"0b",8:"0",16:"0x"}[t]||"";e=n+i(f.toString(t),u||0,"0",false);return s(e,n,r,o,a)};var u=function(e,t,n,r,i,o){if(r!=null){e=e.slice(0,r)}return s(e,"",t,n,i,o)};var a=function(e,r,a,f,l,c,h){var p;var d;var v;var m;var g;if(e==="%%"){return"%"}var y=false,b="",w=false,E=false,S=" ";var x=a.length;for(var T=0;a&&T<x;T++){switch(a.charAt(T)){case" ":b=" ";break;case"+":b="+";break;case"-":y=true;break;case"'":S=a.charAt(T+1);break;case"0":w=true;break;case"#":E=true;break}}if(!f){f=0}else if(f==="*"){f=+t[n++]}else if(f.charAt(0)=="*"){f=+t[f.slice(1,-1)]}else{f=+f}if(f<0){f=-f;y=true}if(!isFinite(f)){throw new Error("sprintf: (minimum-)width must be finite")}if(!c){c="fFeE".indexOf(h)>-1?6:h==="d"?0:undefined}else if(c==="*"){c=+t[n++]}else if(c.charAt(0)=="*"){c=+t[c.slice(1,-1)]}else{c=+c}g=r?t[r.slice(0,-1)]:t[n++];switch(h){case"s":return u(String(g),y,f,c,w,S);case"c":return u(String.fromCharCode(+g),y,f,c,w);case"b":return o(g,2,E,y,f,c,w);case"o":return o(g,8,E,y,f,c,w);case"x":return o(g,16,E,y,f,c,w);case"X":return o(g,16,E,y,f,c,w).toUpperCase();case"u":return o(g,10,E,y,f,c,w);case"i":case"d":p=+g||0;p=Math.round(p-p%1);d=p<0?"-":b;g=d+i(String(Math.abs(p)),c,"0",false);return s(g,d,y,f,w);case"e":case"E":case"f":case"F":case"g":case"G":p=+g;d=p<0?"-":b;v=["toExponential","toFixed","toPrecision"]["efg".indexOf(h.toLowerCase())];m=["toString","toUpperCase"]["eEfFgG".indexOf(h)%2];g=d+Math.abs(p)[v](c);return s(g,d,y,f,w)[m]();default:return e}};return r.replace(e,a)}
</script>
<?php
//		foreach($this->Order->payoptions as );
		$content = json_encode($data2, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
		$tag[$this->settings['label']] =  '<span class="billmate_partpayment"><span class="col1"><b>'.__('Invoice Information','shopp-billmate-partpayment').'</b><input type="text" id="pno" name="billmatepartpayment[pno]" class="required" value="'.$this->Order->partpaymentpno.'" /><label for="pno">'.__('Personal Number', 'shopp-billmate-partpayment').'</label></span><span class="col2" style="width:134px"><img src="'.SHOPP_PLUGINURI.'/gateways'.'/'.($this->module).'/images/bm_delbetalning_l.png"/></span><span style="width: 178px;height:99px;"><span style="padding: 0 !important;width: 264px;">'.__('Billmate Part Payment - Pay from','shopp-billmate-partpayment').' <span id="billmate_partpayment_price"></span>kr/'.__('month','shopp-billmate-partpayment').'</span><span><a id="terms-delbetalning">'.__('Terms of invoice','shopp-billmate-partpayment').'</a></span><input type="text" style="left:-999999px;position:absolute!important;" class="required" name="pclass" id="pclass" /></span><span style="min-width:425px;max-width: 480px;padding-right:none;"><input type="checkbox" checked="checked" name="billmatepartpayment[phone]" id="billmatepartphone" class="required" value="on" style="float: none;"><label for="billmatepartphone" style="float: right;width:95%" class="confirmlabel">'.__('My email address is correct and can be used for invoicing purposes.', 'shopp-billmate-invoice').'</label></span><input  type="image" src="'.SHOPP_PLUGINURI.'/gateways/BillmatePartpayment/images/betala_delbetalning_knapp.gif" class="checkout-button" value="Submit Order" id="checkout-button" name="process"></span><style type="text/css">
		.billmate_partpayment label{
            font-size: 11px!important;
            font-weight: normal!important;
		}
.billmate_partpayment b,.billmate_partpayment span{
    color:#888888
}
#billmate_partpayment_price{
    float: none;
    padding-right: 5px;
    width: auto;
}
.billmate_partpayment a{
    color:blue;
    cursor:pointer;
}
.billmate_partpayment  {
    float: left;
    text-align:left;
    width: 100%;
    font-family:"Helvetica Neue",Arial,Helvetica,"Nimbus Sans L",sans-serif;
}

.billmate_partpayment input[type=text]{
    margin:0px!important;
    width:168px;
}
.billmate_partpayment span{
    float: left;
    padding-right: 33px;
    width: 170px;
		}
.billmate_partpayment img{
    border:0px none!important;
}
.billmate_partpayment span{
font-size:14px;
}
.billmate_partpayment .checkout-button{
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
$ = jQuery;
		jQuery(document).ready(function(){
		
		    $ = jQuery;
            jQuery("#pclassoption").live("change",function(){
                jQuery("#pclass").val(jQuery(this).val());
//                var month = jQuery(this).children(\'option[value=\'+jQuery(this).val()+\']\').attr("monthprice");
//                jQuery("#billmate_partpayment_price").html(month);
            });
			jQuery("#email").change(function(){
				jQuery(".confirmlabel").html(sprintf(confirmemailText, jQuery("#email").val().trim()));
			});
			jQuery(".confirmlabel").html(sprintf(confirmemailText, jQuery("#email").val().trim()));

            jQuery.getScript("https://efinance.se/billmate/base.js", function(){
                    window.$=jQuery;
		            jQuery("#terms-delbetalning").Terms("villkor_delbetalning",{eid: '.$this->settings['eid'].',effectiverate:34});
		            window.$ = jQuery;
            });
        });
		var optionvalue = "";
		var addedoption = true;
		var billmatepartpaymentname = "";
		var billmatepartpayment_countries = "'.implode(',', $this->settings['avail_country']).'";
		 var billmatepclasses ='.$content.';var billmatecount=" '.implode(' ',$countries).' "; jQuery("#billing-country").bind("change",function(){
		    var selectedCountry = jQuery(this).val();
			if(billmatepartpayment_countries.indexOf(selectedCountry)==-1 )
			{
				//jQuery("#paymethod option[]").remove();
			}else{
				if(!addedoption){
				}
			}
            jQuery("#pclass").val("");
            jQuery("#pclassoption").remove();
		    if( billmatecount.indexOf(selectedCountry) != -1 )
		    {
		        var $opt = billmatepclasses[selectedCountry];
		        var reset = true;
		        var amt = 0;
		        $select = "<select name=\'pclassoption\' id=\'pclassoption\'>";
                Object.keys(billmatepclasses.SE).forEach(function(idex ){
                    if(reset){
                        jQuery("#pclass").val(billmatepclasses.SE[idex].pclass_id);
                        reset = false;
                    }
                    amt = amt > billmatepclasses.SE[idex].monthly_cost || amt == 0 ? billmatepclasses.SE[idex].monthly_cost: amt;
                    $select+= "<option value=\'"+billmatepclasses.SE[idex].pclass_id+"\' monthprice=\'"+billmatepclasses.SE[idex].monthly_cost+"\'>"+billmatepclasses.SE[idex].title+" "+billmatepclasses.SE[idex].monthly_cost+" / '.__('per month','shopp-billmate-partpayment').'</option>";
                });
                $select+="</select>";
                jQuery("#billmate_partpayment_price").html(amt);
                jQuery("#pclass").after(jQuery($select)); 
		    }
		});
jQuery(document).ready(function(){
    
});</script>';
		return $tag;
	}

	function process () {
		global $Shopp;

		$Shopping = &$Shopp->Shopping;
		$Order = &$Shopp->Order;
		
		require_once dirname( SHOPP_GATEWAYS ).'/BillmateCore/BillMate.php';
		include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpc.inc");
		include_once(dirname( SHOPP_GATEWAYS )."/BillmateCore/lib/xmlrpcs.inc");
		
		$pno = $this->Order->partpaymentpno;
        
		$phone = $this->Order->partpaymentphone;
		
        $eid  = (int)$this->settings['eid'] ;
        $key = (float)$this->settings['secretkey'];


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
		//new ShoppError(var_export($Shipping->country,1), 'invalid_personal_number');
		//shopp_safe_redirect(shoppurl(false,'checkout'));
		
        $goods_list = array();
        foreach($this->Order->Cart->contents as $item) {
            // echo links for the items

            $flag = stripos( $item->name, 'billmate fee' ) === false ?
                    (stripos( $item->name, 'billmate invoice fee' ) === false? 0 : 16) : 0; 
	        $goods_list[] = array(
		        'qty'   => (int)$item->quantity,
		        'goods' => array(
			        'artno'    => $item->product,
			        'title'    => $item->name,//utf8_decode(Encoding::fixUTF8( $item->name)),
			        'price'    => round($item->unitprice*100,0), //+$item->unittax
			        'vat'      => (float)round($item->taxrate*100,0),
			        'discount' => 0.0,
			        'flags'    => $flag,
		        )

	        );
			$taxrate = $item->taxrate;
        }

		if( $this->Order->Cart->Totals->discount > 0 ){
            $rate = (100+ ($taxrate*100))/100;
            $totalAmt = $this->Order->Cart->Totals->discount;
            $price = $totalAmt-($totalAmt/$rate);
            $discount = $totalAmt - $price;
			
	        $goods_list[] = array(
		        'qty'   => 1,
		        'goods' => array(
			        'artno'    => __('discount','shopp-billmate-partpayment'),
			        'title'    => __('Discount','shopp-billmate-partpayment'),
			        'price'    => -1 * abs( round($this->Order->Cart->Totals->discount *100,0) ),
			        'vat'      => (float) $taxrate*100,
			        'discount' => (float)0,
			        'flags'    => $flag,
		        )
	        );
		}

        if(!empty($this->Order->Cart->Totals->shipping)){
            $taxrate = $taxrate * 100;
            //$rate = (100+$taxrate)/100;
            $totalAmt = $this->Order->Cart->Totals->shipping;
            //$price = $totalAmt-($totalAmt/$rate);
           // $shipping = $totalAmt - $price;


	        $goods_list[] = array(
		        'qty'   => (int)1,
		        'goods' => array(
			        'artno'    => __('Shipping','shopp-billmate-partpayment'),
			        'title'    => __('Shipping','shopp-billmate-partpayment'),
			        'price'    => round($totalAmt*100,0),
			        'vat'      => (float)$taxrate,
			        'discount' => 0,
			        'flags'    => 8,
		        )
	        );
        }
		$pclass = (int)$this->Order->pclass;
	
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
		try{
    		$result1 = $k->AddInvoice($pno,$ship_address,$bill_address,$goods_list,$transaction);
		}catch(Exception $ex ){
		}
 		$this->Order->partpaymentpno = '';
		$this->Order->partpaymentphone = '';
        $this->Order->pclass = false;
		if(!is_array($result1))
		{   

			new ShoppError(__('It is not possible to pay with that method and to choose a different payment method or use a different personal number','shopp-billmate-partpayment'),'billmate_error',SHOPP_TRXN_ERR);
			shopp_redirect(shoppurl(false,'checkout'));
            die;
		}else
		$this->Order->billmateId = $result1[0];
		
		$this->Order->transaction($this->txnid());
		return true;
	}
	function isPermitted(){
	    if(!$this->isConfigured()){
	        new ShoppError( __('Billmate Partpayment is not cofigured', 'shopp-billmate-partpayment'), 2);
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
			'label' => __('Enter your EID.','shopp-billmate-partpayment')
		));

		$this->ui->text(0,array(
			'name' => 'secretkey',
			'value' => $this->settings['secretkey'],
			'size' => 40,
			'label' => __('Enter your Billmate secret key.','shopp-billmate-partpayment')
		));
		$this->ui->checkbox(0,array(
			'name' => 'testmode',
			'label' => __('Enable test mode','shopp-billmate-partpayment'),
			'checked' => $this->settings['testmode']
		));
		$this->ui->multimenu(1,array(
			'name' => 'avail_country',
			'label' => __('Billmate Partpayment activated for customers in these countries','shopp-billmate-cardpay'),
			'multiselect' => 'multiselect',
			'selected' => $this->settings['avail_country'],
		),$available);
	}

} // END class BillmateInvoice

?>
