<?php
include_once 'cryptopiaAPI.php';
include 'nicehashAPI.php';

/**
 * BipCoin NiceHash Orders Bot
 *
 * This Script Updates Orders on NiceHash based on:
 * 1. Total Network Hashrate and block reward, from mining pool
 * 2. Price of BIP on Cryptopia order book
 * 3. Lowest cost to buy hashing on Nicehash order book
 *
 * Sign up for NiceHash with my affiliate link:
 * https://www.nicehash.com/?refby=88315
 * Deposit BTC to your account. Get you API Id and Key to put in this script.
 *
 * Add mining pools: https://www.nicehash.com/index.jsp?p=managepools
 * EU >> host: bip.ms-pool.net.ua port: 8888 username: <BipCoin Address> password: x
 * US >> host: pool.democats.org port: 45591 username: <BipCoin Address> passoword: x
 *
 * Select CryptoNight Algorithm
 * Add one standard order for EU hashing sever and one order for US hasing sever
 * https://www.nicehash.com/index.jsp?p=orders
 *
 * Run this script and schedule it to run every 10-15 Mins.
 */

// Nicehash API Id and Key
$NICEHASH_ID = '######';
$NICEHASH_KEY = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';

$trade_pair = "BIPBTC";
$one_coin_unit = 1000000000000;

// CryptoNote pools API
$bip_pools = [
   ["bip.crypto-coins.club", "http://bip.crypto-coins.club:8118"],
   ["democats.org", "http://pool.democats.org:7693"],
   ["bip.ms-pool.net.ua", "http://bip.ms-pool.net.ua:8117"],
   ["bip.mypool.name", "http://bip.mypool.name:18874"],
   ["bip.cryptonotepool.com", "http://bip.cryptonotepool.com:8121"]
];


try {

   //----- Get My NiceHash open orders
   $nh = New Nicehash( $NICEHASH_KEY, $NICEHASH_ID );

   $myEUOrders = $nh->myOrders(0);
   echo "My Orders: (EU)\n";
   print_r($myEUOrders);

   if (isset($myEUOrders[0]['alive']) &&  $myEUOrders[0]['alive'] == 1) {
      $my_current_hashrate = $myEUOrders[0]['accepted_speed'] * 1000000000;
   } else {
      $my_current_hashrate = 0;
   }

   $myUSOrders = $nh->myOrders(1);
   echo "My Orders: (US)\n";
   print_r($myUSOrders);
 
   if (isset($myUSOrders[0]['alive']) &&  $myUSOrders[0]['alive'] == 1) {
      $my_current_hashrate += $myUSOrders[0]['accepted_speed'] * 1000000000;
   }
   echo "My Current Hashrate: $my_current_hashrate \n";


   //----- Get Bid/Ask Prices from Cryptopia
   $ct = New Cryptopia( '', '' );
   $ct->updatePrices(); // Gets all currency prices, could be modified to just get one currency pair
   $prices = $ct->getPrices();
   echo $trade_pair . "\n";
   echo "Ask Price: " . $ask_price = $prices[$trade_pair]['ask'] . "\n";
   echo "Bid Price: " . $bid_price = $prices[$trade_pair]['bid'] . "\n";
   $mid_price = ( $prices[$trade_pair]['ask'] + $prices[$trade_pair]['bid'] ) / 2;
   echo "Mid Price: $mid_price \n";

   //----- Select between "ask" "mid" "bid" or "custom" price for max price to pay for mining each coin
   //$price = $bid_price;    // bid price
   //$price = $ask_price;    // ask price
   $price = $mid_price;    // avg of bid and ask price
   //$price = .000026;       // custom price override


   //---- Get Network Hashrate and block reward from Pool
   $all_pool_stats = api_poolStats();
   $net_hashrate = api_pool_hashrate($all_pool_stats);


   $pool_stats = $all_pool_stats[0];
   $net_dif = $pool_stats['network']['difficulty'];
   echo "Net Dif: " . $net_dif . "\n";
   echo "coinDifficultyTarget: " . $pool_stats['config']['coinDifficultyTarget'] . "\n";
   $target_hashrate = $net_dif / $pool_stats['config']['coinDifficultyTarget'];
   echo "Net target hashrate: $target_hashrate \n";
   $block_reward = $pool_stats['network']['reward'] / $one_coin_unit;
   echo "Block Reward: " . $block_reward . "\n";
   //$bip_daily_reward = $block_reward * (3600 / $pool_stats['config']['coinDifficultyTarget']) * 24; //based on target hashrate
   $bip_daily_reward = $block_reward * (3600 / ($net_dif / $net_hashrate)) * 24; //based on current network hashrate
   echo "Daily Reward: $bip_daily_reward bip " .
   	$bip_daily_reward * $price . " btc \n";

   $max_net_hashrate = $net_dif / ($pool_stats['config']['coinDifficultyTarget']);  // 120 sec target
   echo "max_net_hashrate: $max_net_hashrate \n";
   $min_net_hashrate = $net_dif / ($pool_stats['config']['coinDifficultyTarget'] * 30); // 30 times the 2 min target i.e. 60 min
   echo "min_net_hashrate: $min_net_hashrate \n";

   $net_hashrate = $net_hashrate - $my_current_hashrate;
   if ($net_hashrate < 0) $net_hashrate = 0;
   echo "Network Hashrate minus my hashrate: $net_hashrate" . PHP_EOL;

   $max_cost_per_mh = 0.90 * $block_reward * $price * 1000000 * 3600 * 24 / $net_dif; // 10% for fees and rejected shares
   echo "Max cost: $max_cost_per_mh \n";

   if ($min_net_hashrate - $net_hashrate < 0 ) 
      $my_min_hashrate = 0;
   else
      $my_min_hashrate = ceil(($min_net_hashrate - $net_hashrate)/1000000 * 100) /100; //round up to 2 decimal places
   echo "my min hashrate: $my_min_hashrate \n";

   $my_max_hashrate = number_format((float) ($max_net_hashrate - $net_hashrate)/1000000, 2, '.', '');
   $half_max_net_hashrate = number_format((float) ($max_net_hashrate/2)/1000000, 2, '.', '');
   if($my_max_hashrate > $half_max_net_hashrate)
      $my_max_hashrate = $half_max_net_hashrate;
   echo "my max hashrate: $my_max_hashrate \n";

   //----- Get NiceHash Order Book
   $orders = $nh->getOrders(0);
   //print_r(array_slice($orders, 0, 3));
   $EUprice = $nh->lowestPrice( $orders, 1000 ) + .0001;
   $bip_price =  $EUprice  / 1000000 * $net_hashrate / $bip_daily_reward;
   $bip_price = number_format((float) $bip_price, 8, '.', '');
   echo "EU Price 1000 workers deep: $EUprice BTC/MH/day $bip_price BTC/BIP\n";

   $USprice = $nh->lowestPrice( $nh->getOrders(1), 1000 ) + .0001;
   $bip_price =  $USprice  / 1000000 * $net_hashrate / $bip_daily_reward;
   $bip_price = number_format((float) $bip_price, 8, '.', '');
   echo "US Price 1000 workers deep: $USprice BTC/MH/day $bip_price BTC/BIP\n";

   sleep(2);

   //----- Update My NiceHash Orders
   if ( isset($myEUOrders[0]['alive']) &&  $myEUOrders[0]['alive'] == 1 && $EUprice != 0 && $EUprice < $USprice ) {
      echo "update EU miner\n";

      if ($max_cost_per_mh > $EUprice)
         $speed_limit = $my_max_hashrate;
      else if ($my_min_hashrate > 0.01)
         $speed_limit = $my_min_hashrate;
      else
         $speed_limit = 0.01;

      echo $nh->setLimit($myEUOrders[0], 0, $speed_limit) . PHP_EOL;

      if($my_min_hashrate > 0 && $max_cost_per_mh < $EUprice) $max_cost_per_mh = $EUprice; // raise the price you are willing to pay when the net hashrate is below the min hashrate

      if ($EUprice > $max_cost_per_mh) $EUprice = $max_cost_per_mh;
      sleep(2);
      echo $nh->setPrice($myEUOrders[0], 0, $max_cost_per_mh, $EUprice) . PHP_EOL;
      if (isset($myUSOrders[0]['alive']) &&  $myUSOrders[0]['alive'] == 1) {
         sleep(2);
         $price = $max_cost_per_mh*.75;
         if ($price > $USprice) {
            $price = $USprice;
         }
         echo $nh->setLimit($myUSOrders[0], 1, 0.01) . PHP_EOL;
         sleep(2);
         echo $nh->setPrice($myUSOrders[0], 1, $max_cost_per_mh, $price) . PHP_EOL;
      }

   } else if ($USprice != 0 && isset($myUSOrders[0]['alive']) &&  $myUSOrders[0]['alive'] == 1) {
      echo "update US miner\n";

      if ($max_cost_per_mh > $USprice)
         $speed_limit = $my_max_hashrate;
      else if ($my_min_hashrate > 0.01)
         $speed_limit = $my_min_hashrate;
      else
         $speed_limit = 0.01;
      echo $nh->setLimit($myUSOrders[0], 1, $speed_limit) . PHP_EOL;

      if($my_min_hashrate > 0 && $max_cost_per_mh < $USprice) $max_cost_per_mh = $USprice; // raise the price you are willing to pay when the net hashrate is below the min hashrate

      if ($USprice > $max_cost_per_mh) $USprice = $max_cost_per_mh;
      sleep(2);
      echo $nh->setPrice($myUSOrders[0], 1, $max_cost_per_mh, $USprice) . PHP_EOL;
      if (isset($myEUOrders[0]['alive']) &&  $myEUOrders[0]['alive'] == 1) {
         sleep(2);
         $price = $max_cost_per_mh*.75;
         if ($price > $EUprice) {
            $price = $EUprice;
         }
         echo $nh->setLimit($myEUOrders[0], 0, 0.01) . PHP_EOL;
         sleep(2);
         echo $nh->setPrice($myEUOrders[0], 0, $max_cost_per_mh, $price) . PHP_EOL;
      }
   }
} catch(Exception $e) {
   echo '' . $e->getMessage() . PHP_EOL;
}

//----- Mining Pool Stats
function api_poolStats() {
   global $bip_pools;
	static $ch = null;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
   curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
   foreach ($bip_pools as $pool) {
   	$url = $pool[1] . "/stats";
   	curl_setopt($ch, CURLOPT_URL, $url );
   	$res = curl_exec($ch);
      if ($res === false) echo "Can't connect to pool: " . $pool[0] . " Error:" . curl_error($ch) . PHP_EOL;      
      else $results[] = json_decode($res, true);
   }
	if ($results === false) throw new Exception('Could not connect to any mining pools');
	return $results;
}

function api_pool_hashrate($pool_stats) {
   $net_hashrate = 0;
   foreach ($pool_stats as $pool) {
      $pool_hashrate = $pool['pool']['hashrate'];
      echo "Pool Hashrate: $pool_hashrate" . PHP_EOL;
      $net_hashrate += $pool_hashrate;
   }
   echo "Network Hashrate: $net_hashrate" . PHP_EOL;
   return $net_hashrate;
}

?>