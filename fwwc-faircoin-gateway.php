<?php
/*
Faircoin Payments for WooCommerce

*/

// Include everything
include(dirname(__FILE__) . '/fwwc-include-all.php');






//---------------------------------------------------------------------------
add_action('plugins_loaded', 'FWWC__plugins_loaded__load_faircoin_gateway', 0);
//---------------------------------------------------------------------------

//###########################################################################
// Hook payment gateway into WooCommerce

function FWWC__plugins_loaded__load_faircoin_gateway ()
{

    if (!class_exists('WC_Payment_Gateway'))
    	// Nothing happens here is WooCommerce is not loaded
    	return;

	//=======================================================================
	/**
	 * Faircoin Payment Gateway
	 *
	 * Provides a Faircoin Payment Gateway
	 *
	 * @class 		FWWC_Faircoin
	 * @extends		WC_Payment_Gateway
	 * @version
	 * @package
	 * @author 		santi Punto0 Coop
	 */
	class FWWC_Faircoin extends WC_Payment_Gateway
	{
		//-------------------------------------------------------------------
	    /**
	     * Constructor for the gateway.
	     *
	     * @access public
	     * @return void
	     */
		public function __construct()
		{
      			$this->id 		= 'faircoin';
      			$this->icon 		= plugins_url('/images/faircoin_px32.png', __FILE__);	// 32 pixels high
     			$this->has_fields 	= false;
      			$this->method_title     = __( 'Faircoin', 'woocommerce' );

			// Load the settings.
			$this->init_settings();

			// Define user set variables
			$this->title 		= $this->settings['title'];	// The title which the user is shown on the checkout – retrieved from the settings which init_settings loads.
			$this->service_provider = $this->settings['service_provider'];
			$this->electrum_master_public_key = $this->settings['electrum_master_public_key'];
			$this->faircoin_addr_merchant = $this->settings['faircoin_addr_merchant'];	// Forwarding address where all product payments will aggregate.
			
			$this->confirmations = $this->settings['confirmations'];
			$this->exchange_rate_type = $this->settings['exchange_rate_type'];
			$this->exchange_multiplier = $this->settings['exchange_multiplier'];
			$this->description 	= $this->settings['description'];	// Short description about the gateway which is shown on checkout.
			$this->instructions = $this->settings['instructions'];	// Detailed payment instructions for the buyer.
			$this->instructions_multi_payment_str  = __('You may send payments from multiple accounts to reach the total required.', 'woocommerce');
			$this->instructions_single_payment_str = __('You must pay in a single payment in full.', 'woocommerce');

			// Load the form fields.
			$this->init_form_fields();

			// Actions
      			if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) )
			        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
      			else
				add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options')); // hook into this action to save options in the backend

	    		add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'FWWC__thankyou_page')); // hooks into the thank you page after payment

	    		// Customer Emails
	    		add_action('woocommerce_email_before_order_table', array(&$this, 'FWWC__email_instructions'), 10, 2); // hooks into the email template to show additional details

			// Hook IPN callback logic
			if (version_compare (WOOCOMMERCE_VERSION, '2.0', '<'))
				add_action('init', array(&$this, 'FWWC__maybe_faircoin_ipn_callback'));
			else
				add_action('woocommerce_api_' . strtolower(get_class($this)), array($this,'FWWC__maybe_faircoin_ipn_callback'));

			// Validate currently set currency for the store. Must be among supported ones.
			if (!$this->FWWC__is_gateway_valid_for_use()) $this->enabled = false;
                        //Add Payment method to admin email
	                add_action( 'woocommerce_email_after_order_table', 'add_payment_method_to_admin_new_order', 15, 2 );
	    	}
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Check if this gateway is enabled and available for the store's default currency
	     *
	     * @access public
	     * @return bool
	     */
	    function FWWC__is_gateway_valid_for_use(&$ret_reason_message=NULL)
	    {
	    	$valid = true;

	    	//----------------------------------
	    	// Validate settings
	    	if (!$this->service_provider)
	    	{
	    		$reason_message = __("Faircoin Service Provider is not selected", 'woocommerce');
	    		$valid = false;
	    	}
/*	    	else if ($this->service_provider=='blockchain.info')
	    	{
	    		if ($this->faircoin_addr_merchant == '')
	    		{
		    		$reason_message = __("Your personal faircoin address is not selected", 'woocommerce');
		    		$valid = false;
	    		}
	    		else if ($this->faircoin_addr_merchant == '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2')
	    		{
		    		$reason_message = __("Your personal faircoin address is invalid. The address specified is Bitcoinway.com's donation address :)", 'woocommerce');
		    		$valid = false;
	    		}
	    	}*/
	    	else if ($this->service_provider=='electrum-wallet')
	    	{
	    		if (!$this->electrum_master_public_key)
	    		{
		    		$reason_message = __("Pleace specify Electrum Master Public Key (Launch your electrum wallet, select Preferences->Import/Export->Master Public Key->Show)", 'woocommerce');
		    		$valid = false;
		    	}
// TODO: Chequear propiamente la dirección electrum2
//	    		else if (!preg_match ('/^[a-f0-9]{128}$/', $this->electrum_master_public_key))
//	    		{
//		    		$reason_message = __("Electrum Master Public Key is invalid. Must be 128 characters long, consisting of digits and letters: 'a b c d e f'", 'woocommerce');
//		    		$valid = false;
//		    	}
		    	else if (!extension_loaded('gmp') && !extension_loaded('bcmath'))
		    	{
		    		$reason_message = __("ERROR: neither 'bcmath' nor 'gmp' math extensions are loaded For Electrum wallet options to function. Contact your hosting company and ask them to enable either 'bcmath' or 'gmp' extensions. 'gmp' is preferred (much faster)! \n", 'woocommerce');
		    		$valid = false;
		    	}
	    	}

	    	if (!$valid)
	    	{
	    		if ($ret_reason_message !== NULL)
	    			$ret_reason_message = $reason_message;
	    		return false;
	    	}
	    	//----------------------------------

	    	//----------------------------------
	    	// Validate connection to exchange rate services

	   		$store_currency_code = get_woocommerce_currency();
	   		if ($store_currency_code != 'FAI')
	   		{
 	                           $currency_rate = FWWC__get_exchange_rate_per_faircoin ($store_currency_code, 'getfirst', 'vwap', false);  
//				   $msg = "Received rate : ".$currency_rate;
//				   FWWC__log_event (__FILE__, __LINE__, $msg);

					if (!$currency_rate)
					{
						$valid = false;
						// Assemble error message.
						$error_msg = "ERROR: Cannot determine exchange rates (for '$store_currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.";
						$extra_error_message = "";
						$fns = array ('file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec');
						$fns = array_filter ($fns, 'FWWC__function_not_exists');
						$extra_error_message = "";
						if (count($fns))
							$extra_error_message = "The following PHP functions are disabled on your server: " . implode (", ", $fns) . ".";
   						$reason_message = str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $error_msg);
					    	if ($ret_reason_message !== NULL)
					    		$ret_reason_message = $reason_message;
						 FWWC__log_event (__FILE__, __LINE__, "Fallo accediendo a getfaircoin : ".$reason_message);
						return false;
						
					}
			}
 	     	return true;
	    }
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Initialise Gateway Settings Form Fields
	     *
	     * @access public
	     * @return void
	     */
	    function init_form_fields()
	    {
		    // This defines the settings we want to show in the admin area.
		    // This allows user to customize payment gateway.
		    // Add as many as you see fit.
		    // See this for more form elements: http://wcdocs.woothemes.com/codex/extending/settings-api/

	    	//-----------------------------------
	    	// Assemble currency ticker.
	   		$store_currency_code = get_woocommerce_currency();
	   		if ($store_currency_code == 'FAI')
	   			$currency_code = 'USD';
	   		else
	   			$currency_code = $store_currency_code;

			$currency_ticker = FWWC__get_exchange_rate_per_faircoin ($currency_code, 'getfirst', 'vwap', true);
	    	//-----------------------------------

	    	//-----------------------------------
	    	// Payment instructions
	    	$payment_instructions = '
<table class="fwwc-payment-instructions-table" id="fwwc-payment-instructions-table">
  <tr class="bpit-table-row">
    <td colspan="2">' . __('Please send your faircoin payment as follows:', 'woocommerce') . '</td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-amount">
      ' . __('Amount', 'woocommerce') . ' (<strong>FAI</strong>):
    </td>
    <td class="bpit-td-value bpit-td-value-amount">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#CC0000;font-weight: bold;font-size: 120%;">
      	{{{FAIRCOINS_AMOUNT}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-faiaddr">
      Address:
    </td>
    <td class="bpit-td-value bpit-td-value-faiaddr">
      <div style="border:1px solid #FCCA09;padding:2px 6px;margin:2px;background-color:#FCF8E3;border-radius:4px;color:#555;font-weight: bold;font-size: 120%;">
        {{{FAIRCOINS_ADDRESS}}}
      </div>
    </td>
  </tr>
  <tr class="bpit-table-row">
    <td style="vertical-align:middle;" class="bpit-td-name bpit-td-name-qr">
	    QR Code:
    </td>
    <td class="bpit-td-value bpit-td-value-qr">
      <div style="border:1px solid #FCCA09;padding:5px;margin:2px;background-color:#FCF8E3;border-radius:4px;">
        <a href="faircoin://{{{FAIRCOINS_ADDRESS}}}?amount={{{FAIRCOINS_AMOUNT}}}"><img src="https://blockchain.info/qr?data=faircoin://{{{FAIRCOINS_ADDRESS}}}?amount={{{FAIRCOINS_AMOUNT}}}&size=180" style="vertical-align:middle;border:1px solid #888;" /></a>
      </div>
    </td>
  </tr>
</table>

' . __('Please note:', 'woocommerce') . '
<ol class="bpit-instructions">
    <li>' . __('You must make a payment within 1 hour, or your order will be cancelled', 'woocommerce') . '</li>
    <li>' . __('As soon as your payment is received in full you will receive email confirmation.', 'woocommerce') . '</li>
    <li>{{{EXTRA_INSTRUCTIONS}}}</li>
</ol>
';
				$payment_instructions = trim ($payment_instructions);

	    	$payment_instructions_description = '
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	' . __( 'Specific instructions given to the customer to complete Faircoins payment.<br />You may change it, but make sure these tags will be present: <b>{{{FAIRCOINS_AMOUNT}}}</b>, <b>{{{FAIRCOINS_ADDRESS}}}</b> and <b>{{{EXTRA_INSTRUCTIONS}}}</b> as these tags will be replaced with customer - specific payment details.', 'woocommerce' ) . '
						  </p>
						  <p class="description" style="width:50%;float:left;width:49%;">
					    	Payment Instructions, original template (for reference):<br />
					    	<textarea rows="2" onclick="this.focus();this.select()" readonly="readonly" style="width:100%;background-color:#f1f1f1;height:4em">' . $payment_instructions . '</textarea>
						  </p>
					';
				$payment_instructions_description = trim ($payment_instructions_description);
	    	//-----------------------------------

	    	$this->form_fields = array(
				'enabled' => array(
								'title' => __( 'Enable/Disable', 'woocommerce' ),
								'type' => 'checkbox',
								'label' => __( 'Enable Faircoin Payments', 'woocommerce' ),
								'default' => 'yes'
							),
				'title' => array(
								'title' => __( 'Title', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
								'default' => __( 'Faircoin Payment', 'woocommerce' )
							),

				'service_provider' => array(
								'title' => __('Faircoin service provider', 'woocommerce' ),
								'type' => 'select',
								'options' => array(
									''  => __( 'Please choose your provider', 'woocommerce' ),
									'electrum-wallet'  => __( 'Your own Electrum wallet', 'woocommerce' ),
									'External API' => __( 'External API (Not working - deprecated - use Electrum instead)', 'woocommerce' ),
									),
								'default' => '',
								'description' => $this->service_provider?__("Please select your Faircoin service provider and press [Save changes]. Then fill-in necessary details and press [Save changes] again.<br />Recommended setting: <b>Your own Electrum wallet</b>", 'woocommerce'):__("Recommended setting: 'Your own Electrum wallet'. <a href='https://electrum.fair-coin.org/' target='_blank'>Free download of Electrum wallet here</a>.", 'woocommerce'),
							),

				'electrum_master_public_key' => array(
								'title' => __( 'Electrum wallet\'s Master Public Key', 'woocommerce' ),
								'type' => 'textarea',
								'default' => "",
								'css'     => $this->service_provider!='electrum-wallet'?'display:none;':'',
								'disabled' => $this->service_provider!='electrum-wallet'?true:false,
								'description' => $this->service_provider!='electrum-wallet'?__('Available when Faircoin service provider is set to: <b>Your own Electrum wallet</b>.', 'woocommerce'):__('1. Launch <a href="http://electrum.org/" target="_blank">Electrum wallet</a> and get Master Public Key value from:<br />Wallet -> Master Public Key, or:<br />older version of Electrum: Preferences -> Import/Export -> Master Public Key -> Show.<br />Copy long number string and paste it in this field.<br />
									2. Change "gap limit" value to bigger value (to make sure youll see the total balance on your wallet):<br />
									Click on "Console" tab and run this command: <tt>wallet.storage.put(\'gap_limit\',100)</tt>
									<br />Then restart Electrum wallet to activate new gap limit. You may do it later at any time - gap limit does not affect functionlity of your online store.
									<br />If your online store receives lots of orders in faircoins - you might need to set gap limit to even bigger value.
									', 'woocommerce'),
							),

				'faircoin_addr_merchant' => array(
								'title' => __( 'Your personal faircoin address', 'woocommerce' ),
								'type' => 'text',
								'css'     => $this->service_provider!='blockchain.info'?'display:none;':'',
								'disabled' => $this->service_provider!='blockchain.info'?true:false,
								'description' => $this->service_provider!='blockchain.info'?__('Available when Faircoin service provider is set to: <b>Blockchain.info</b>', 'woocommerce'):__( 'Your own faircoin address (such as: 1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2) - where you would like the payment to be sent. When customer sends you payment for the product - it will be automatically forwarded to this address by blockchain.info APIs.', 'woocommerce' ),
								'default' => '',
							),


				'confirmations' => array(
								'title' => __( 'Number of confirmations required before accepting payment', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'After a transaction is broadcast to the Faircoin network, it may be included in a block that is published to the network. When that happens it is said that one <a href="https://en.faircoin.it/wiki/Confirmation" target="_blank">confirmation has occurred</a> for the transaction. With each subsequent block that is found, the number of confirmations is increased by one. To protect against double spending, a transaction should not be considered as confirmed until a certain number of blocks confirm, or verify that transaction. <br />6 is considered very safe number of confirmations, although it takes longer to confirm.', 'woocommerce' ),
								'default' => '6',
							),


				'exchange_rate_type' => array(
								'title' => __('Exchange rate calculation type', 'woocommerce' ),
								'type' => 'select',
								'disabled' => $store_currency_code=='FAI'?true:false,
								'options' => array(
									'vwap' => __( 'Weighted Average', 'woocommerce' ),
									'realtime' => __( 'Real time', 'woocommerce' ),
									'bestrate' => __( 'Most profitable', 'woocommerce' ),
									),
								'default' => 'vwap',
								'description' => ($store_currency_code=='FAI'?__('<span style="color:red;"><b>Disabled</b>: Applies only for stores with non-faircoin default currency.</span><br />', 'woocommerce'):'') .
									__('<b>Weighted Average</b> (recommended): <a href="http://en.wikipedia.org/wiki/Volume-weighted_average_price" target="_blank">weighted average</a> rates polled from a number of exchange services<br />
										<b>Real time</b>: the most recent transaction rates polled from a number of exchange services.<br />
										<b>Most profitable</b>: pick better exchange rate of all indicators (most favorable for merchant). Calculated as: MIN (Weighted Average, Real time)') . '<br />' . $currency_ticker,
							),
				'exchange_multiplier' => array(
								'title' => __('Exchange rate multiplier', 'woocommerce' ),
								'type' => 'text',
								'disabled' => $store_currency_code=='FAI'?true:false,
								'description' => ($store_currency_code=='FAI'?__('<span style="color:red;"><b>Disabled</b>: Applies only for stores with non-faircoin default currency.</span><br />', 'woocommerce'):'') .
									__('Extra multiplier to apply to convert store default currency to faircoin price. <br />Example: <b>1.05</b> - will add extra 5% to the total price in faircoins. May be useful to compensate merchant\'s loss to fees when converting faircoins to local currency, or to encourage customer to use faircoins for purchases (by setting multiplier to < 1.00 values).', 'woocommerce' ),
								'default' => '1.00',
							),
				'description' => array(
								'title' => __( 'Customer Message', 'woocommerce' ),
								'type' => 'text',
								'description' => __( 'Initial instructions for the customer at checkout screen', 'woocommerce' ),
								'default' => __( 'Please proceed to the next screen to see necessary payment details.', 'woocommerce' )
							),
				'instructions' => array(
								'title' => __( 'Payment Instructions (HTML)', 'woocommerce' ),
								'type' => 'textarea',
								'description' => $payment_instructions_description,
								'default' => $payment_instructions,
							),
				);
	    }
		//-------------------------------------------------------------------
/*
///!!!
									'<table>' .
									'	<tr><td colspan="2">' . __('Please send your faircoin payment as follows:', 'woocommerce' ) . '</td></tr>' .
									'	<tr><td>Amount (฿): </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:#CC0000;">{{{FAIRCOINS_AMOUNT}}}</div></td></tr>' .
									'	<tr><td>Address: </td><td><div style="border:1px solid #CCC;padding:2px 6px;margin:2px;background-color:#FEFEF0;border-radius:4px;color:blue;">{{{FAIRCOINS_ADDRESS}}}</div></td></tr>' .
									'</table>' .
									__('Please note:', 'woocommerce' ) .
									'<ol>' .
									'   <li>' . __('You must make a payment within 4 hours, or your order will be cancelled', 'woocommerce' ) . '</li>' .
									'   <li>' . __('As soon as your payment is received in full you will receive email confirmation with order delivery details.', 'woocommerce' ) . '</li>' .
									'   <li>{{{EXTRA_INSTRUCTIONS}}}</li>' .
									'</ol>'

*/

		//-------------------------------------------------------------------
		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 *
		 * @access public
		 * @return void
		 */
		public function admin_options()
		{
			$validation_msg = "";
			$store_valid    = $this->FWWC__is_gateway_valid_for_use ($validation_msg);

			// After defining the options, we need to display them too; thats where this next function comes into play:
	    	?>
	    	<h3><?php _e('Faircoin Payment', 'woocommerce'); ?></h3>
	    	<p>
	    		<?php _e('Allows to accept payments in faircoin. <a href="https://fair-coin.org" target="_blank">Faircoins</a> are peer-to-peer, decentralized digital currency that enables instant payments from anyone to anyone, anywhere in the world
<p style="border:1px solid #890e4e;padding:5px 10px;color:#004400;background-color:#FFF;"></p>
	    			',
	    				'woocommerce'); ?>
	    	</p>
	    	<?php
	    		echo $store_valid ? ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;">' . __('Faircoin payment gateway is operational','woocommerce') . '</p>') : ('<p style="border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;">' . __('Faircoin payment gateway is not operational: ','woocommerce') . $validation_msg . '</p>');
	    	?>
	    	<table class="form-table">
	    	<?php
	    		// Generate the HTML For the settings form.
	    		$this->generate_settings_html();
	    	?>
			</table><!--/.form-table-->
	    	<?php
	    	}
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	  	// Hook into admin options saving.
    	    	public function process_admin_options()
	    	{
    	    		// Call parent
    	    		parent::process_admin_options();

    	    		if (isset($_POST) && is_array($_POST))
    	    		{
	    			$fwwc_settings = FWWC__get_settings ();
	  			if (!isset($fwwc_settings['gateway_settings']) || !is_array($fwwc_settings['gateway_settings']))
	  				$fwwc_settings['gateway_settings'] = array();

	    			$prefix        = 'woocommerce_faircoin_';
	    			$prefix_length = strlen($prefix);

	    			foreach ($_POST as $varname => $varvalue)
	    			{
	    				if (strpos($varname, 'woocommerce_faircoin_') === 0)
	    				{
	    					$trimmed_varname = substr($varname, $prefix_length);
	    					if ($trimmed_varname != 'description' && $trimmed_varname != 'instructions')
	    						$fwwc_settings['gateway_settings'][$trimmed_varname] = $varvalue;
	    				}
	    			}
	  			// Update gateway settings within FWWC own settings for easier access.
				FWWC__update_settings ($fwwc_settings);
			}
    	   	}
		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Process the payment and return the result
	     *
	     * @access public
	     * @param int $order_id
	     * @return array
	     */
		function process_payment ($order_id)
		{
			$order = new WC_Order ($order_id);

			//-----------------------------------
			// Save faircoin payment info together with the order.
			// Note: this code must be on top here, as other filters will be called from here and will use these values ...
			//
			// Calculate realtime faircoin price (if exchange is necessary)

			$exchange_rate = FWWC__get_exchange_rate_per_faircoin (get_woocommerce_currency(), 'getfirst', 'vwap', false);
			/// $exchange_rate = FWWC__get_exchange_rate_per_faircoin (get_woocommerce_currency(), $this->exchange_rate_retrieval_method, $this->exchange_rate_type);
			if (!$exchange_rate)
			{
				$msg = 'ERROR: Cannot determine Faircoin exchange rate. Possible issues: store server does not allow outgoing connections, exchange rate servers are blocking incoming connections or down. ' .
					   'You may avoid that by setting store currency directly to Faircoin(FAI)';
	      			FWWC__log_event (__FILE__, __LINE__, $msg);
      				exit ('<h2 style="color:red;">' . $msg . '</h2>');
			}

			$order_total_in_fai   = ($order->get_total() / $exchange_rate);
			if (get_woocommerce_currency() != 'FAI')
				// Apply exchange rate multiplier only for stores with non-faircoin default currency.
				$order_total_in_fai = $order_total_in_fai * $this->exchange_multiplier;

			$order_total_in_fai   = sprintf ("%.8f", $order_total_in_fai);

  			$faircoins_address = false;

  			$order_info =
  			array (
  				'order_id'				=> $order_id,
  				'order_total'			=> $order_total_in_fai,
  				'order_datetime'  => date('Y-m-d H:i:s T'),
  				'requested_by_ip'	=> @$_SERVER['REMOTE_ADDR'],
  				);

  			$ret_info_array = array();
/*
			if ($this->service_provider == 'blockchain.info')
			{
				$faircoin_addr_merchant = $this->faircoin_addr_merchant;
				$secret_key = substr(md5(microtime()), 0, 16);	# Generate secret key to be validate upon receiving IPN callback to prevent spoofing.
				$callback_url = trailingslashit (home_url()) . "?wc-api=FWWC_Faircoin&secret_key={$secret_key}&faircoinway=1&src=bcinfo&order_id={$order_id}"; // http://www.example.com/?faircoinway=1&order_id=74&src=bcinfo
	   		FWWC__log_event (__FILE__, __LINE__, "Calling FWWC__generate_temporary_faircoin_address__blockchain_info(). Payments to be forwarded to: '{$faircoin_addr_merchant}' with callback URL: '{$callback_url}' ...");

	   			// This function generates temporary faircoin address and schedules IPN callback at the same
				$ret_info_array = FWWC__generate_temporary_faircoin_address__blockchain_info ($faircoin_addr_merchant, $callback_url);
				$faircoins_address = @$ret_info_array['generated_faircoin_address'];
			}*/
			if ($this->service_provider == 'electrum-wallet')
		             // Generate faircoin address for electrum wallet provider.
			     $ret_info_array = FWWC__get_faircoin_address_for_payment__electrum ($this->electrum_master_public_key, $order_info);

/*
            $ret_info_array = array (
               'result'                      => 'success', // OR 'error'
               'message'										 => '...',
               'host_reply_raw'              => '......',
               'generated_faircoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
               ); */




//                        $msg = "ret_info_array: ".$ret_info_array['result']." ".$ret_info_array['generated_faircoin_address'];
//                        FWWC__log_event (__FILE__, __LINE__, $msg); 

                        if ($ret_info_array['result'] == 'success')
				    $faircoins_address = $ret_info_array['generated_faircoin_address'];
//                        FWWC__log_event (__FILE__, __LINE__, $faircoins_address);
			if (!$faircoins_address)
			{
				$msg = "ERROR: cannot generate faircoin address for the order: " . @$ret_info_array['message'];
      				FWWC__log_event (__FILE__, __LINE__, $msg);
      				exit ('<h2 style="color:red;">' . $msg . '</h2>');
			}

//   		        FWWC__log_event (__FILE__, __LINE__, " Generated/found faircoin address: '{$faircoins_address}' for order_id " . $order_id);
/*
			if ($this->service_provider == 'blockchain.info')
			{
	     	update_post_meta (
	     		$order_id, 			// post id ($order_id)
	     		'secret_key', 	// meta key
	     		$secret_key 		// meta value. If array - will be auto-serialized
	     		);
	 		}
*/
		     	update_post_meta (
     				$order_id, 			// post id ($order_id)
		     		'order_total_in_fai', 	// meta key
     				$order_total_in_fai 	// meta value. If array - will be auto-serialized
				);
		     	update_post_meta (
     				$order_id, 			// post id ($order_id)
		     		'faircoins_address',	// meta key
     				$faircoins_address 	// meta value. If array - will be auto-serialized
		     		);
		     	update_post_meta (
     				$order_id, 			// post id ($order_id)
		     		'faircoins_paid_total',	// meta key
     				"0" 	// meta value. If array - will be auto-serialized
     				);
		     	update_post_meta (
     				$order_id, 			// post id ($order_id)
		     		'faircoins_refunded',	// meta key
     				"0" 	// meta value. If array - will be auto-serialized
		     		);
     			update_post_meta (
     				$order_id, 				// post id ($order_id)
		     		'_incoming_payments',	// meta key. Starts with '_' - hidden from UI.
     				array()					// array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
     				);
     			update_post_meta (
     				$order_id, 				// post id ($order_id)
     				'_payment_completed',	// meta key. Starts with '_' - hidden from UI.
     				0					// array (array('datetime'=>'', 'from_addr'=>'', 'amount'=>''),)
     				);
			//-----------------------------------
			// The faircoin gateway does not take payment immediately, but it does need to change the orders status to on-hold
			// (so the store owner knows that faircoin payment is pending).
			// We also need to tell WooCommerce that it needs to redirect to the thankyou page – this is done with the returned array
			// and the result being a success.

			global $woocommerce;

			// Updating the order status:
			// Mark as on-hold (we're awaiting for faircoins payment to arrive). Send email to customer and admin
			$order->update_status('on-hold', __('Awaiting faircoin payment to arrive', 'woocommerce'));

			// Remove cart
			$woocommerce->cart->empty_cart();

			// Empty awaiting payment session
		        // unset( $woocommerce->session->order_awaiting_payment );

			$url = $this->get_return_url( $order );


         		  // Return thank you redirect
			$result = array('result' => 'success','redirect' => $url);
FWWC__log_event(__FILE__, __LINE__, "Resultado payment : ".$result['result']." New order : ".$order_id." FAI address : ".$faircoins_address." Return url ".$result['redirect']);
			return $result;
		}

		//-------------------------------------------------------------------

		//-------------------------------------------------------------------
	    /**
	     * Output for the order received page.
	     *
	     * @access public
	     * @return void
	     */
		function FWWC__thankyou_page($order_id)
		 {
		// FWWC__thankyou_page is hooked into the "thank you" page and in the simplest case can just echo’s the description.
//                        FWWC__log_event (__FILE__, __LINE__, "Begin thank you");

			// Get order object.
			// http://wcdocs.woothemes.com/apidocs/class-WC_Order.html
			$order = new WC_Order($order_id);

			// Assemble detailed instructions.
			$order_total_in_fai   = get_post_meta($order->id, 'order_total_in_fai',   true); // set single to true to receive properly unserialized array
			$faircoins_address = get_post_meta($order->id, 'faircoins_address', true); // set single to true to receive properly unserialized array


			$instructions = $this->instructions;
			$instructions = str_replace ('{{{FAIRCOINS_AMOUNT}}}',  $order_total_in_fai, $instructions);
			$instructions = str_replace ('{{{FAIRCOINS_ADDRESS}}}', $faircoins_address, 	$instructions);
			$instructions =	str_replace ('{{{EXTRA_INSTRUCTIONS}}}',
           				$this->instructions_multi_payment_str,
					$instructions
					);
                $order->add_order_note( __("Order instructions: price=&#3647;{$order_total_in_fai}, incoming account:{$faircoins_address}", 'woocommerce'));
//                FWWC__log_event (__FILE__, __LINE__, "end thank you: ".$instructions);
	        echo wpautop (wptexturize ($instructions));
		}
//-------------------------------------------------------------------

//-------------------------------------------------------------------
	    /**
	     * Add content to the WC emails.
	     *
	     * @access public
	     * @param WC_Order $order
	     * @param bool $sent_to_admin
	     * @return void
	     */
	       function FWWC__email_instructions ($order, $sent_to_admin)
	       {
//                 FWWC__log_event (__FILE__, __LINE__, "Begin email to admin : ".$sent_to_admin);

	    	if ($sent_to_admin) return;
	    	if (!in_array($order->status, array('pending', 'on-hold'), true)) return;
	    	if ($order->payment_method !== 'faircoin') return;

	    	// Assemble payment instructions for email
		$order_total_in_fai   = get_post_meta($order->id, 'order_total_in_fai',   true); // set single to true to receive properly unserialized array
		$faircoins_address = get_post_meta($order->id, 'faircoins_address', true); // set single to true to receive properly unserialized array


		$instructions = $this->instructions;
		$instructions = str_replace ('{{{FAIRCOINS_AMOUNT}}}',  $order_total_in_fai, 	$instructions);
		$instructions = str_replace ('{{{FAIRCOINS_ADDRESS}}}', $faircoins_address, 	$instructions);
		$instructions =	str_replace ('{{{EXTRA_INSTRUCTIONS}}}',
					$this->instructions_multi_payment_str,
					$instructions  );
//                FWWC__log_event (__FILE__, __LINE__, "End email");
                echo wpautop (wptexturize ($instructions));
		}
		//-------------------------------------------------------------------
	} // End class
	//-------------------------------------------------------------------
	//-----------------------------------------------------------------------
	// Hook into WooCommerce - add necessary hooks and filters
	add_filter ('woocommerce_payment_gateways', 	'FWWC__add_faircoin_gateway' );

	// Disable unnecessary billing fields.
	/// Note: it affects whole store.
	 add_filter ('woocommerce_checkout_fields' , 	'FWWC__woocommerce_checkout_fields' );

	 add_filter ('woocommerce_currencies', 			'FWWC__add_fai_currency');
	 add_filter ('woocommerce_currency_symbol', 		'FWWC__add_fai_currency_symbol', 10, 2);

	// Change [Order] button text on checkout screen.
        /// Note: this will affect all payment methods.
        /// add_filter ('woocommerce_order_button_text', 'FWWC__order_button_text');
	//-----------------------------------------------------------------------
	// Nos enviamos una copia a nosotros de las facturas cuando hayan sido completadas
        add_filter( 'woocommerce_email_headers', 'FWWC_headers_filter_function', 10, 2);
	function FWWC_headers_filter_function( $headers, $object ) {
//	    FWWC__log_event(__FILE__,__LINE_, "Objeto recibido : " $object;
	    if ($object == 'customer_invoice') {
		$email = get_option('admin_email');
        	$headers .= "BCC: ".$email. " \r\n";
    	    }
	    return $headers;
	}
	//=======================================================================

	//=======================================================================
	/**
	 * Add the gateway to WooCommerce
	 *
	 * @access public
	 * @param array $methods
	 * @package
	 * @return array
	 */
	function FWWC__add_faircoin_gateway( $methods )
	{
		$methods[] = 'FWWC_Faircoin';
		return $methods;
	}
	//=======================================================================

	//=======================================================================
	// Our hooked in function - $fields is passed via the filter!
	function FWWC__woocommerce_checkout_fields ($fields)
	{
	     unset($fields['order']['order_comments']);
	     unset($fields['billing']['billing_first_name']);
	     unset($fields['billing']['billing_last_name']);
	     unset($fields['billing']['billing_company']);
	     unset($fields['billing']['billing_address_1']);
	     unset($fields['billing']['billing_address_2']);
	     unset($fields['billing']['billing_city']);
	     unset($fields['billing']['billing_postcode']);
	     unset($fields['billing']['billing_country']);
	     unset($fields['billing']['billing_state']);
	     unset($fields['billing']['billing_phone']);
	     return $fields;
	}
	//=======================================================================

	//=======================================================================
	function FWWC__add_fai_currency($currencies)
	{
	     $currencies['FAI'] = __( 'Faircoin f', 'woocommerce' );
	     return $currencies;
	}
	//=======================================================================

	//=======================================================================
	function FWWC__add_fai_currency_symbol($currency_symbol, $currency)
	{
		switch( $currency )
		{
			case 'FAI':
				$currency_symbol = 'f';
				break;
		}

		return $currency_symbol;
	}
	//=======================================================================

	//=======================================================================
 	function FWWC__order_button_text () { return 'Continue'; }
	//=======================================================================
	//===========================================================================
	function FWWC__process_payment_completed_for_order ($order_id, $faircoins_paid=false)
	{
		if (!$order_id)
			return false;
	//        FWWC__log_event (__FILE__, __LINE__, "Processing payment completed. Order : ".$order_id. " Fairs :".$faircoins_paid);
        	global $woocommerce;
		$order = new WC_Order($order_id);
		if ($faircoins_paid)
		{

        		update_post_meta ($order_id, 'faircoins_paid_total', $faircoins_paid);
			// Payment completed
			// Make sure this logic is done only once, in case customer keep sending payments :)
			if (!get_post_meta($order_id, '_payment_completed', true))
			{
				update_post_meta ($order_id, '_payment_completed', '1');
				FWWC__log_event (__FILE__, __LINE__, "Success: order '{$order_id}' paid in full. Processing and notifying customer ...");

				$order->add_order_note( __('Order paid complete', 'woocommerce') );
	                	$order->payment_complete();
                        	// Notificamos al usuario con factura
				$email = new WC_Email_Customer_Invoice();
				$email->trigger($order_id);
				// Notificamos al admin
		        	// ... //
				// ... //
				return true;
			}
		}
		else
		{
			// Payment expired
			if (!get_post_meta ($order_id, '_payment_completed', 1))
			{
				FWWC__log_event (__FILE__, __LINE__, "Order '{$order_id}' expired. Processing and notifying customer ...");

				$order->add_order_note( __('Order paid expired', 'woocommerce') );
		        	$order->update_status("failed",'No enough funds arrived at time');
				// Notificamos al usuario
//           			$email = new WC_Email_Cancelled_Order($order_id);
//              		$email->trigger($order_id);
				// Notificamos al admin
//				$email = new WC_Email_Admin_Cancelled_Order(); // No encuentra esta clase...
//				$email->trigger($order_id);
				return true;
			}
			else 
// El pago se completa en el último momento o error. Hay que volver a chequear si el pago está completo, pero no se hará normalmente xq la dirección ha expirado 
			{
				FWWC__log_event (__FILE__, __LINE__, "El pago de esta orden '{order_id}' ya ha sido completado ...");
				// ... //
				return true; // Para limpiar la dirección 
			}
		}
		return false;
	}
	//===========================================================================

	function add_payment_method_to_admin_new_order( $order, $is_admin_email ) 
	{
        	if ( $is_admin_email )
			echo '<p><strong>Payment Method:</strong> ' . $order->payment_method_title . '</p>';
        }

//===========================================================================
} // End initial function
