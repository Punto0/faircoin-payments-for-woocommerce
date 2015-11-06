<?php
/*
Faircoin Payments for WooCommerce

*/

//===========================================================================
/*
   Input:
   ------
      $order_info =
         array (
            'order_id'        => $order_id,
            'order_total'     => $order_total_in_fai,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );
*/
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_faircoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
       );
*/
//

function FWWC__get_faircoin_address_for_payment__electrum ($electrum_mpk, $order_info)
{
   global $wpdb;

   // status = "unused", "assigned", "used"
   $fai_addresses_table_name     = $wpdb->prefix . 'fwwc_fai_addresses';
   $origin_id                    = 'electrum.mpk.' . md5($electrum_mpk);

   $fwwc_settings = FWWC__get_settings ();
   $funds_received_value_expires_in_secs = $fwwc_settings['funds_received_value_expires_in_mins'] * 60;
   $assigned_address_expires_in_secs     = $fwwc_settings['assigned_address_expires_in_mins'] * 60;

   $clean_address = NULL;
   $current_time = time();

   if ($fwwc_settings['reuse_expired_addresses'])
   {
      $reuse_expired_addresses_freshb_query_part =
      	"OR (`status`='assigned'
      		AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
      		AND (('$current_time' - `received_funds_checked_at`) < '$funds_received_value_expires_in_secs')
      		)";
   }
   else
      $reuse_expired_addresses_freshb_query_part = "";

   //-------------------------------------------------------
   // Quick scan for ready-to-use address
   // NULL == not found
   // Retrieve:
   //     'unused'   - with fresh zero balances
   //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
   //
   // Hence - any returned address will be clean to use.
   $query =
      "SELECT `fai_address` FROM `$fai_addresses_table_name`
         WHERE `origin_id`='$origin_id'
         AND `total_received_funds`='0'
         AND (`status`='unused' $reuse_expired_addresses_freshb_query_part)
         ORDER BY `index_in_wallet` ASC
         LIMIT 1;"; // Try to use lower indexes first
   $clean_address = $wpdb->get_var ($query);

   //-------------------------------------------------------

         if (!$clean_address)
   	{
//       FWWC__log_event (__FILE__, __LINE__,"NO CLEAN ADDRESS! ".$query); 
      //-------------------------------------------------------
      // Find all unused addresses belonging to this mpk with possibly (to be verified right after) zero balances
      // Array(rows) or NULL
      // Retrieve:
      //    'unused'    - with old zero balances
      //    'unknown'   - ALL
      //    'assigned'  - expired with old zero balances (if 'reuse_expired_addresses' is true)
      //
      // Hence - any returned address with freshened balance==0 will be clean to use.
	   if ($fwwc_settings['reuse_expired_addresses'])
           {
              $reuse_expired_addresses_oldb_query_part =
	      	"OR (`status`='assigned'
	      		AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
	      		AND (('$current_time' - `received_funds_checked_at`) > '$funds_received_value_expires_in_secs')
	      		)";
			}  
	    else
	      $reuse_expired_addresses_oldb_query_part = "";

      $query =
         "SELECT * FROM `$fai_addresses_table_name`
            WHERE `origin_id`='$origin_id'
	    AND `total_received_funds`='0'
            AND (
               `status`='unused'
                OR `status`='unknown'
                $reuse_expired_addresses_oldb_query_part
               )
            ORDER BY `index_in_wallet` ASC;"; // Try to use lower indexes first
       $addresses_to_verify_for_zero_balances_rows = $wpdb->get_results ($query, ARRAY_A);
//       FWWC__log_event (__FILE__, __LINE__,"Buscando address from query : ".$query);
       if (!is_array($addresses_to_verify_for_zero_balances_rows))
          $addresses_to_verify_for_zero_balances_rows = array();
      //-------------------------------------------------------
      // Try to re-verify balances of existing addresses (with old or non-existing balances) before reverting to slow operation of generating new address.
      //
      $blockchains_api_failures = 0;
      foreach ($addresses_to_verify_for_zero_balances_rows as $address_to_verify_for_zero_balance_row)
      {
         $address_to_verify_for_zero_balance = $address_to_verify_for_zero_balance_row['fai_address'];
 	 $msg = "Verifyng adddress for zero balance : ".$address_to_verify_for_zero_balance;
	 FWWC__log_event (__FILE__, __LINE__, $msg);
         $ret_info_array = FWWC__getreceivedbyaddress_info ($address_to_verify_for_zero_balance, 0, $fwwc_settings['blockchain_api_timeout_secs']);
         FWWC__log_event (__FILE__, __LINE__,"Funds : ".$ret_info_array['balance']);
         if ($ret_info_array['balance'] === false)
         {
           $blockchains_api_failures ++;
           if ($blockchains_api_failures >= $fwwc_settings['max_blockchains_api_failures'])
           {
             // Allow no more than 3 contigious blockchains API failures. After which return error reply.
             $ret_info_array = array (
               'result'                      => 'error',
               'message'                     => $ret_info_array['message'],
               'host_reply_raw'              => $ret_info_array['host_reply_raw'],
               'generated_faircoin_address'   => false,
               );
            return $ret_info_array;
            }
         }
         else
         {
             if ($ret_info_array['balance'] == 0)
             {
             // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
             $msg = "Reusing address : ".$address_to_verify_for_zero_balance;
             FWWC__log_event (__FILE__, __LINE__, $msg);
             $clean_address    = $address_to_verify_for_zero_balance;
             break;
             }
                 else
	     {
// Balance at this address suddenly became non-zero!
// It means either order was paid after expiration or "unknown" address suddenly showed up with non-zero balance or payment was sent to this address outside of this online store business.
// Mark it as 'revalidate' so cron job would check if that's possible delayed payment.
		  $address_meta    = FWWC_unserialize_address_meta (@$address_to_verify_for_zero_balance_row['address_meta']);
		  if (isset($address_meta['orders'][0]))
		  	$new_status = 'revalidate';	// Past orders are present. There is a chance (for cron job) to match this payment to past (albeit expired) order.
		  else
		  	$new_status = 'used';				// No orders were ever placed to this address. Likely payment was sent to this address outside of this online store business.
		$current_time = time();
                $query ="UPDATE `$fai_addresses_table_name`
			 SET `status`='$new_status',
			     `total_received_funds` = '{$ret_info_array['balance']}',
			      `received_funds_checked_at`='$current_time'
			  WHERE `fai_address`='$address_to_verify_for_zero_balance';";
		$ret_code = $wpdb->query ($query);
	      }
           }
       } // Fin del foreach
    } // Fin del if !clean_address
//    else
//        FWWC__log_event (__FILE__, __LINE__,"CLEAN ADDRESS FOUND ".$clean_address); 
//-------------------------------------------------------

  if (!$clean_address)
  {
    // Still could not find unused virgin address. Time to generate it from scratch.
    /*
    Returns:
       $ret_info_array = array (
          'result'                      => 'success', // 'error'
          'message'                     => '', // Failed to find/generate faircoin address',
          'host_reply_raw'              => '', // Error. No host reply availabe.',
          'generated_faircoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
          );
    */
    $ret_addr_array = FWWC__generate_new_faircoin_address_for_electrum_wallet ($fwwc_settings, $electrum_mpk);
    if ($ret_addr_array['result'] == 'success')
	    $clean_address = $ret_addr_array['generated_faircoin_address'];
  }
//   $msg = "Clean address: ".$clean_address;
//   FWWC__log_event (__FILE__, __LINE__, $msg);
  if ($clean_address)
   {
   /*
         $order_info =
         array (
            'order_id'     => $order_id,
            'order_total'  => $order_total_in_fai,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );

*/
/*
      $address_meta =
         array (
            'orders' =>
               array (
                  // All orders placed on this address in reverse chronological order
                  array (
                     'order_id'     => $order_id,
                     'order_total'  => $order_total_in_fai,
                     'order_datetime'  => date('Y-m-d H:i:s T'),
                     'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                  ),
                  array (
                     ...
                  ),
               ),
            'other_meta_info' => array (...)
         );
      */

      // Prepare `address_meta` field for this clean address.
      $address_meta = $wpdb->get_var ("SELECT `address_meta` FROM `$fai_addresses_table_name` WHERE `fai_address`='$clean_address'");
      $address_meta = FWWC_unserialize_address_meta ($address_meta);

      if (!isset($address_meta['orders']) || !is_array($address_meta['orders']))
         $address_meta['orders'] = array();

      array_unshift ($address_meta['orders'], $order_info);    // Prepend new order to array of orders
      if (count($address_meta['orders']) > 10)
         array_pop ($address_meta['orders']);   // Do not keep history of more than 10 unfullfilled orders per address.
      $address_meta_serialized = FWWC_serialize_address_meta ($address_meta);

      // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
      //
      $current_time = time();
      $remote_addr  = $order_info['requested_by_ip'];
      $query =
      "UPDATE `$fai_addresses_table_name`
         SET
            `total_received_funds` = '0',
            `received_funds_checked_at`='$current_time',
            `status`='assigned',
            `assigned_at`='$current_time',
            `last_assigned_to_ip`='$remote_addr',
            `address_meta`='$address_meta_serialized'
        WHERE `fai_address`='$clean_address';";
      $ret_code = $wpdb->query ($query);

      $ret_info_array = array (
         'result'                      => 'success',
         'message'                     => "",
         'host_reply_raw'              => "",
         'generated_faircoin_address'   => $clean_address,
         );
//    $msg = "Generated address array: ".$ret_info_array['result'].$ret_info_array['generated_faircoin_address'];
//    FWWC__log_event (__FILE__, __LINE__, $msg); 
    return $ret_info_array;
  }
  //-------------------------------------------------------

   $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => 'Failed to find/generate faircoin address. ' . $ret_addr_array['message'],
      'host_reply_raw'              => $ret_addr_array['host_reply_raw'],
      'generated_faircoin_address'   => false,
      );
   $msg = "ERROR  : ".$ret_info_array['message']." - Generated address : ".$ret_info_array['generated_faircoin_address'];
   FWWC__log_event (__FILE__, __LINE__, $msg);
   return $ret_info_array;
}
//===========================================================================

//===========================================================================
/*
Returns:
   $ret_info_array = array (
      'result'                      => 'success', // 'error'
      'message'                     => '', // Failed to find/generate faircoin address',
      'host_reply_raw'              => '', // Error. No host reply availabe.',
      'generated_faircoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
      );
*/
// If $fwwc_settings or $electrum_mpk are missing - the best attempt will be made to manifest them.
// For performance reasons it is better to pass in these vars. if available.
//
function FWWC__generate_new_faircoin_address_for_electrum_wallet ($fwwc_settings=false, $electrum_mpk=false)
{
  global $wpdb;

  $fai_addresses_table_name = $wpdb->prefix . 'fwwc_fai_addresses';

  if (!$fwwc_settings)
    $fwwc_settings = FWWC__get_settings ();

  if (!$electrum_mpk)
  {
    // Try to retrieve it from copy of settings.
    $electrum_mpk = @$fwwc_settings['gateway_settings']['electrum_master_public_key'];

    if (!$electrum_mpk || @$fwwc_settings['gateway_settings']['service_provider'] != 'electrum-wallet')
    {
      // Faircoin gateway settings either were not saved
     $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => 'No MPK passed and either no MPK present in copy-settings or service provider is not Electrum',
        'host_reply_raw'              => '',
        'generated_faircoin_address'   => false,
        );
     return $ret_info_array;
    }
  }

  $origin_id = 'electrum.mpk.' . md5($electrum_mpk);

  $funds_received_value_expires_in_secs = $fwwc_settings['funds_received_value_expires_in_mins'] * 60;
  $assigned_address_expires_in_secs     = $fwwc_settings['assigned_address_expires_in_mins'] * 60;

  $clean_address = false;

  // Find next index to generate
  $next_key_index = $wpdb->get_var ("SELECT MAX(`index_in_wallet`) AS `max_index_in_wallet` FROM `$fai_addresses_table_name` WHERE `origin_id`='$origin_id';");
  if ($next_key_index === NULL)
    $next_key_index = $fwwc_settings['starting_index_for_new_fai_addresses']; // Start generation of addresses from index #2 (skip two leading wallet's addresses)
  else
    $next_key_index = $next_key_index+1;  // Continue with next index

  $total_new_keys_generated = 0;
  $blockchains_api_failures = 0;
  do
  {
    $new_fai_address = FWWC__MATH_generate_faircoin_address_from_mpk2 ($electrum_mpk, $next_key_index);
    //FWWC__log_event (__FILE__, __LINE__, "new_fair_address : ".$new_fai_address );
    // Todo : Chequear que la dirección retornada sea una dir fair válida
    $ret_info_array  = FWWC__getreceivedbyaddress_info ($new_fai_address, 0, $fwwc_settings['blockchain_api_timeout_secs']);
    $total_new_keys_generated ++;
    if ($ret_info_array['balance'] === false)
      $status = 'unknown';
    else if ($ret_info_array['balance'] == 0)
      $status = 'unused'; // Newly generated address with freshly checked zero balance is unused and will be assigned.
    else
      $status = 'used';   // Generated address that was already used to receive money.

    $funds_received                  = ($ret_info_array['balance'] === false)?-1:$ret_info_array['balance'];
    $received_funds_checked_at_time  = ($ret_info_array['balance'] === false)?0:time();

    // Insert newly generated address into DB
    $query =
      "INSERT INTO `$fai_addresses_table_name`
      (`fai_address`, `origin_id`, `index_in_wallet`, `total_received_funds`, `received_funds_checked_at`, `status`) VALUES
      ('$new_fai_address', '$origin_id', '$next_key_index', '$funds_received', '$received_funds_checked_at_time', '$status');";
    $ret_code = $wpdb->query ($query);

    $next_key_index++;

    if ($ret_info_array['balance'] === false)
    {
      $blockchains_api_failures ++;
      if ($blockchains_api_failures >= $fwwc_settings['max_blockchains_api_failures'])
      {
        // Allow no more than 3 contigious blockchains API failures. After which return error reply.
        $ret_info_array = array (
          'result'                      => 'error',
          'message'                     => $ret_info_array['message'],
          'host_reply_raw'              => $ret_info_array['host_reply_raw'],
          'generated_faircoin_address'   => false,
          );
        return $ret_info_array;
      }
    }
    else
    {
      if ($ret_info_array['balance'] == 0)
      {
        // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
          $clean_address = $new_fai_address;
      }
    }

    if ($clean_address)
      break;

    if ($total_new_keys_generated >= $fwwc_settings['max_unusable_generated_addresses'])
    {
      // Stop it after generating of 20 unproductive addresses.
      // Something is wrong. Possibly old merchant's wallet (with many used addresses) is used for new installation. - For this case 'starting_index_for_new_fai_addresses'
      //  needs to be proper set to high value.
      $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => "Problem: Generated '$total_new_keys_generated' addresses and none were found to be unused. Possibly old merchant's wallet (with many used addresses) is used for new installation. If that is the case - 'starting_index_for_new_fai_addresses' needs to be proper set to high value",
        'host_reply_raw'              => '',
        'generated_faircoin_address'   => false,
        );
      return $ret_info_array;
    }

  } while (true);

  // Here only in case of clean address.
  $ret_info_array = array (
    'result'                      => 'success',
    'message'                     => '',
    'host_reply_raw'              => '',
    'generated_faircoin_address'   => $clean_address,
    );
  //$msg = "Generated address array: ".$ret_info_array['result'].$ret_info_array['generated_faircoin_address'];
 // FWWC__log_event (__FILE__, __LINE__, $msg);
  return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Function makes sure that returned value is valid array
function FWWC_unserialize_address_meta ($flat_address_meta)
{
   $unserialized = @unserialize($flat_address_meta);
   if (is_array($unserialized))
      return $unserialized;
   return array();
}
//===========================================================================

//===========================================================================
// Function makes sure that value is ready to be stored in DB
function FWWC_serialize_address_meta ($address_meta_arr)
{
   return FWWC__safe_string_escape(serialize($address_meta_arr));
}
//===========================================================================

//===========================================================================
/*
$ret_info_array = array (
  'result'                      => 'success',
  'message'                     => "",
  'host_reply_raw'              => "",
  'balance'                     => false == error, else - balance
  );
*/
function FWWC__getreceivedbyaddress_info ($fai_address, $required_confirmations=0, $api_timeout=10)
{
   if ($required_confirmations)
   {
      $confirmations_url_part_bec = "/$required_confirmations";
      $confirmations_url_part_bci = "/$required_confirmations";
   }
   else
   {
      $confirmations_url_part_bec = "";
      $confirmations_url_part_bci = "";
   }

   // Help: https://chain.fair-coin.org/chain/FairCoin/q/
   // https://chain.fair-coin.org/chain/FairCoin/q/getreceivedbyaddress/fNUaPBpZod7CYXiHyvjYA2hT94ZqP2MAuK == 1
   $url = 'https://chain.fair-coin.org/chain/FairCoin/q/addressbalance/'.$fai_address;
   $funds_received = FWWC__file_get_contents ($url, true, $api_timeout);

//   FWWC__log_event (__FILE__, __LINE__, "address request : ".$fai_address." - funds received " . $funds_received);
   if (is_numeric($funds_received))
   {
    $ret_info_array = array (
      'result'                      => 'success',
      'message'                     => "",
      'host_reply_raw'              => "",
      'balance'                     => $funds_received,
      );
  }
  else
  {
    $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => "chain.fair-org api failure, can not check balance",
      'host_reply_raw'              => $funds_received, 
      'balance'                     => false,
     );
     FWWC__log_event (__FILE__, __LINE__,"Can not check ADDRESS! ".$url);
  }
  return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Input:
// ------

//    $callback_url => IPN notification URL upon received payment at generated address.
//    $forwarding_faircoin_address => Where all payments received at generated address should be ultimately forwarded to.
//
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_faircoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
       );
*/
//
/*
function FWWC__generate_temporary_faircoin_address__blockchain_info ($forwarding_faircoin_address, $callback_url)
{
   //--------------------------------------------
   // Normalize inputs.
   $callback_url = urlencode(urldecode($callback_url));  // Make sure it is URL encoded.


   $blockchain_api_call = "https://blockchain.info/api/receive?method=create&address={$forwarding_faircoin_address}&anonymous=false&callback={$callback_url}";
   FWWC__log_event (__FILE__, __LINE__, "Calling chair.fair-coin.org API: " . $blockchain_api_call);
   $result = @FWWC__file_get_contents ($blockchain_api_call, true);
   if ($result)
   {
      $json_obj = @json_decode(trim($result));
      if (is_object($json_obj))
      {
         $generated_faircoin_address = @$json_obj->input_address;
         if (strlen($generated_faircoin_address) > 20)
         {
            $ret_info_array = array (
               'result'                      => 'success',
               'message'                     => '',
               'host_reply_raw'              => $result,
               'generated_faircoin_address'   => $generated_faircoin_address,
               );
            return $ret_info_array;
         }
      }
   }

   $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => 'chain.fair-coin.org API failure: ' . $result,
      'host_reply_raw'              => $result,
      'generated_faircoin_address'   => false,
      );
   return $ret_info_array;
}*/
//===========================================================================

//===========================================================================
// Returns:
//    success: number of currency units (dollars, etc...) would take to convert to 1 faircoin, ex: "15.32476".
//    failure: false
//
// $currency_code, one of: USD, AUD, CAD, CHF, CNY, DKK, EUR, GBP, HKD, JPY, NZD, PLN, RUB, SEK, SGD, THB
// $rate_retrieval_method
//		'getfirst' -- pick first successfully retireved rate
//		'getall'   -- retrieve from all possible exchange rate services and then pick the best rate.
//
// $rate_type:
//    'vwap'    	-- weighted average as per: http://en.wikipedia.org/wiki/VWAP
//    'realtime' 	-- Realtime exchange rate
//    'bestrate'  -- maximize number of faircoins to get for item priced in currency: == min (avg, vwap, sell)
//                 This is useful to ensure maximum faircoin gain for stores priced in other currencies.
//                 Note: This is the least favorable exchange rate for the store customer.
// $get_ticker_string - true - ticker string of all exchange types for the given currency.

function FWWC__get_exchange_rate_per_faircoin ($currency_code, $rate_retrieval_method = 'getfirst', $rate_type = 'vwap', $get_ticker_string)
{
//   FWWC__log_event (__FILE__, __LINE__,"Begin get_exchange");
   if ($currency_code == 'FAI')
      return "1.00";   // 1:1

//  Do not limit support with present list of currencies. This was originally created because exchange rate APIs did not support many, but today
//	they do support many more currencies, hence this check is removed for now.
//   if (!@in_array($currency_code, FWWC__get_settings ('supported_currencies_arr')))
//      return false;

	$fwwc_settings = FWWC__get_settings ();

	$current_time  = time();
	$cache_hit     = false;
	$requested_cache_method_type = $rate_retrieval_method . '|' . $rate_type;
	$ticker_string = "<span style='color:darkgreen;'>Current Rates for 1 Faircoin (in {$currency_code})={{{EXCHANGE_RATE}}}</span>";
	$ticker_string_error = "<span style='color:red;background-color:#FFA'>WARNING: Cannot determine exchange rates (for '$currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.</span>";
	$this_currency_info = @$fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type];

//        FWWC__log_event (__FILE__, __LINE__,"Looking cache. Last checked : ".$this_currency_info['time-last-checked']. " currency : ".$currency_code." type : ".$requested_cache_method_type); 

	if ($this_currency_info)
	{
          $exchange_rate = @$fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['exchange_rate'];
          $exchange_rate = $fwwc_settings['exchange_rates']['EUR']['getfirst|vwap']['exchange_rate'];

          $cache_last_checked = @$fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['time-last-checked'];
	  $delta = $current_time - $cache_last_checked;
          $cache_time = $fwwc_settings['cache_exchange_rates_for_minutes'] * 60;

//	  FWWC__log_event (__FILE__, __LINE__,"Last Cache : ".$cache_last_checked." current time : ".$current_time." delta calculated ".$delta. " cache time : ".$cache_time);
	  if ($delta < $cache_time)
	  {
	     // Exchange rates cache hit
	     // Use cached value as it is still fresh.
//	        FWWC__log_event (__FILE__, __LINE__,"Cache hit : ".$exchange_rate);
         	if ($get_ticker_string)
	  		return str_replace('{{{EXCHANGE_RATE}}}', $exchange_rate, $ticker_string);
	  	else
	  		return $exchange_rate;
	  }
	}
        else
          FWWC__log_event (__FILE__, __LINE__,"No set, no looking for ".$requested_cache_method_type." rate in cache: ".$this_currency_info['exchange_rate']); 
       // Chequeamos getfaircoin.net si no se ha disparado la cache
       $fair_rate = FWWC__get_exchange_rate_from_getfaircoin($currency_code, $rate_type, $fwwc_settings);
       if ($fair_rate)
         FWWC__update_exchange_rate_cache ($currency_code, 'getfirst|vwap', $fair_rate);
       if ($get_ticker_string)
	{
	  if ($fair_rate) {
			$msg = str_replace('{{{EXCHANGE_RATE}}}', $fair_rate, $ticker_string);			
//			FWWC__log_event (__FILE__, __LINE__, $msg);
			return str_replace('{{{EXCHANGE_RATE}}}', $fair_rate, $ticker_string);
		} else
		{
			$extra_error_message = "";
			$fns = array ('file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec');
			$fns = array_filter ($fns, 'FWWC__function_not_exists');
			if (count($fns))
				$extra_error_message = "The following PHP functions are disabled on your server: " . implode (", ", $fns) . ".";
			$msg = str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $ticker_string_error);
//			FWWC__log_event (__FILE__, __LINE__, $msg);
			return str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $ticker_string_error);
		}
	}
	return $fair_rate;
}
//===========================================================================

//===========================================================================
function FWWC__function_not_exists ($fname) { return !function_exists($fname); }
//===========================================================================

//===========================================================================
function FWWC__update_exchange_rate_cache ($currency_code, $requested_cache_method_type, $exchange_rate){
  // Save new currency exchange rate info in cache
  $time = time();
  FWWC__log_event (__FILE__, __LINE__,"Updating cache rate : " .$currency_code." rate type : ".$requested_cache_method_type." exchange rate : ".$exchange_rate);
  $fwwc_settings = FWWC__get_settings ();   // Re-get settings in case other piece updated something while we were pulling exchange rate API's...
  $fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['time-last-checked'] = $time;
  $fwwc_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['exchange_rate'] = $exchange_rate;
  FWWC__update_settings ($fwwc_settings);
}
//===========================================================================

//===========================================================================

function FWWC__get_exchange_rate_from_getfaircoin ($currency_code, $rate_type, $fwwc_settings)
{
    $rate = 0.05;
    $source_url	= "https://www.getfaircoin.net/api/eur-fair";
    $result = @FWWC__file_get_contents ($source_url, false, $fwwc_settings['exchange_rate_api_timeout_secs']);
    $obj = json_decode($result,false);
    $rate = $obj->{'eur-fair'}; 
    $msg = "Rate from getfaircoin.net retrieved: ".$rate;
    FWWC__log_event (__FILE__, __LINE__, $msg);
    return $rate;
}
//===========================================================================

//===========================================================================
/*
  Get web page contents with the help of PHP cURL library
   Success => content
   Error   => if ($return_content_on_error == true) $content; else FALSE;
*/
function FWWC__file_get_contents ($url, $return_content_on_error=false, $timeout=60, $user_agent=FALSE)
{

   if (!function_exists('curl_init'))
      {
      $ret_val = @file_get_contents ($url);

			return $ret_val;
      }

   $options = array(
      CURLOPT_URL            => $url,
      CURLOPT_RETURNTRANSFER => true,     // return web page
      CURLOPT_HEADER         => false,    // don't return headers
      CURLOPT_ENCODING       => "",       // handle compressed
      CURLOPT_USERAGENT      => $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12"), // who am i

      CURLOPT_AUTOREFERER    => true,     // set referer on redirect
      CURLOPT_CONNECTTIMEOUT => $timeout,       // timeout on connect
      CURLOPT_TIMEOUT        => $timeout,       // timeout on response in seconds.
      CURLOPT_FOLLOWLOCATION => true,     // follow redirects
      CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
      CURLOPT_SSL_VERIFYPEER => false,    // Disable SSL verification
      );

   $ch      = curl_init   ();

   if (function_exists('curl_setopt_array'))
      {
      curl_setopt_array      ($ch, $options);
      }
   else
      {
      // To accomodate older PHP 5.0.x systems
      curl_setopt ($ch, CURLOPT_URL            , $url);
      curl_setopt ($ch, CURLOPT_RETURNTRANSFER , true);     // return web page
      curl_setopt ($ch, CURLOPT_HEADER         , false);    // don't return headers
      curl_setopt ($ch, CURLOPT_ENCODING       , "");       // handle compressed
      curl_setopt ($ch, CURLOPT_USERAGENT      , $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12")); // who am i
      curl_setopt ($ch, CURLOPT_AUTOREFERER    , true);     // set referer on redirect
      curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT , $timeout);       // timeout on connect
      curl_setopt ($ch, CURLOPT_TIMEOUT        , $timeout);       // timeout on response in seconds.
      curl_setopt ($ch, CURLOPT_FOLLOWLOCATION , true);     // follow redirects
      curl_setopt ($ch, CURLOPT_MAXREDIRS      , 10);       // stop after 10 redirects
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER  , false);    // Disable SSL verifications
      }

   $content = curl_exec   ($ch);
   $err     = curl_errno  ($ch);
   $header  = curl_getinfo($ch);
   // $errmsg  = curl_error  ($ch);


   curl_close             ($ch);

   if (!$err && $header['http_code']==200)
      return trim($content);
   else
   {
      if ($return_content_on_error)
         return trim($content);
      else
         return FALSE;
   }
}
//===========================================================================

//===========================================================================
// Credits: http://www.php.net/manual/en/function.mysql-real-escape-string.php#100854
function FWWC__safe_string_escape ($str="")
{
   $len=strlen($str);
   $escapeCount=0;
   $targetString='';
   for ($offset=0; $offset<$len; $offset++)
   {
     switch($c=$str{$offset})
     {
         case "'":
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '"':
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '\\':
                 $escapeCount++;
                 $targetString.=$c;
                 break;
         default:
                 $escapeCount=0;
                 $targetString.=$c;
     }
   }
   return $targetString;
}
//===========================================================================

//===========================================================================
// Syntax:
//    FWWC__log_event (__FILE__, __LINE__, "Hi!");
//    FWWC__log_event (__FILE__, __LINE__, "Hi!", "/..");
//    FWWC__log_event (__FILE__, __LINE__, "Hi!", "", "another_log.php");
function FWWC__log_event ($filename, $linenum, $message, $prepend_path="", $log_file_name='__log.php')
{
   $log_filename   = dirname(__FILE__) . $prepend_path . '/' . $log_file_name;
   $logfile_header = "<?php exit(':-)'); ?>\n" . '/* =============== Faircoin Woo LOG file =============== */' . "\r\n";
   $logfile_tail   = "\r\nEND";

   // Delete too long logfiles.
   //if (@file_exists ($log_filename) && filesize($log_filename)>1000000)
   //   unlink ($log_filename);

   $filename = basename ($filename);

   if (@file_exists ($log_filename))
      {
      // 'r+' non destructive R/W mode.
      $fhandle = @fopen ($log_filename, 'r+');
      if ($fhandle)
         @fseek ($fhandle, -strlen($logfile_tail), SEEK_END);
      }
   else
      {
      $fhandle = @fopen ($log_filename, 'w');
      if ($fhandle)
         @fwrite ($fhandle, $logfile_header);
      }

   if ($fhandle)
      {
      @fwrite ($fhandle, "\r\n// " . $_SERVER['REMOTE_ADDR'] . '(' . $_SERVER['REMOTE_PORT'] . ')' . ' -> ' . date("Y-m-d, G:i:s T") . "|" . FWWC_VERSION . "/" . FWWC_EDITION . "|$filename($linenum)|: " . $message . $logfile_tail);
      @fclose ($fhandle);
      }
}
//===========================================================================

//===========================================================================
function FWWC__SubIns ()
{
  $fwwc_settings = FWWC__get_settings ();
  $elists = @$fwwc_settings['elists'];
  if (!is_array($elists))
  	$elists = array();

	$email = get_settings('admin_email');
	if (!$email)
	  $email = get_option('admin_email');

	if (!$email)
		return;


	if (isset($elists[FWWC_PLUGIN_NAME]) && count($elists[FWWC_PLUGIN_NAME]))
	{

		return;
	}


	$elists[FWWC_PLUGIN_NAME][$email] = '1';

//	$ignore = file_get_contents ('http://www.faircoinway.com/NOTIFY/?email=' . urlencode($email) . "&c1=" . urlencode(FWWC_PLUGIN_NAME) . "&c2=" . urlencode(FWWC_EDITION));

	$fwwc_settings['elists'] = $elists;
  	FWWC__update_settings ($fwwc_settings);

	return true;
}
//===========================================================================

//===========================================================================
function FWWC__send_email ($email_to, $email_from, $subject, $plain_body)
{
   $message = "
   <html>
   <head>
   <title>$subject</title>
   </head>
   <body>" . $plain_body . "
   </body>
   </html>
   ";
        $msg = "Sending email : ".$message;
        FWWC__log_event (__FILE__, __LINE__, $msg);

   // To send HTML mail, the Content-type header must be set
   $headers  = 'MIME-Version: 1.0' . "\r\n";
   $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

   // Additional headers
   $headers .= "From: " . $email_from . "\r\n";    //"From: Birthday Reminder <birthday@example.com>" . "\r\n";

   // Mail it
   $ret_code = @mail ($email_to, $subject, $message, $headers);

   return $ret_code;
}
//===========================================================================
