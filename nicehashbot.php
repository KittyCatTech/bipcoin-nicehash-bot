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
   //echo "My Orders: (EU)\n";
   //print_r($myEUOrders);

   $myUSOrders = $nh->myOrders(1);
   //echo "My Orders: (US)\n";
   //print_r($myUSOrders);
 

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
   $pool_stats = api_poolStats();
   echo "Net Dif: " . $pool_stats['network']['difficulty'] . "\n";
   echo "coinDifficultyTarget: " . $pool_stats['config']['coinDifficultyTarget'] . "\n";
   $net_hashrate = $pool_stats['network']['difficulty'] / $pool_stats['config']['coinDifficultyTarget'];
   echo "Net hashrate: $net_hashrate \n";
   echo "Reward: " . $pool_stats['network']['reward'] / 1000000000000 . "\n";
   $bip_daily_reward = $pool_stats['network']['reward'] / 1000000000000 * 30 * 24;
   echo "Daily Reward: $bip_daily_reward bip " .
   	$pool_stats['network']['reward'] / 1000000000000 * 30 * 24 * $price . " btc \n";

   $my_hash = $net_hashrate  / 1000000 / 4;
   $speed_limit = number_format((float) $my_hash, 2, '.', '');
   echo "MH/s to buy: $speed_limit\n";
   // Took out ($my_hash + $net_hashrate) b/c other miners drop out when you join.
   $my_rew = $pool_stats['network']['reward'] / 1000000000000 * 30 * 24 * ($my_hash / ($net_hashrate));
   $max_cost = 0.90 * $my_rew * $price * 1000000 / $my_hash; // 10% for fees and rejected shares
   echo "Max cost per mH: $max_cost \n";


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
      echo $nh->setLimit($myEUOrders[0], 0, $speed_limit) . PHP_EOL;
      if ($EUprice > $max_cost) $EUprice = $max_cost;
      sleep(2);
      echo $nh->setPrice($myEUOrders[0], 0, $max_cost, $EUprice) . PHP_EOL;
      if (isset($myUSOrders[0]['alive']) &&  $myUSOrders[0]['alive'] == 1) {
         sleep(2);
         $price = $max_cost*.75;
         if ($price > $USprice) {
            $price = $USprice;
            echo $nh->setLimit($myUSOrders[0], 1, $speed_limit) . PHP_EOL;
         } else {
            echo $nh->setLimit($myUSOrders[0], 1, 0.01) . PHP_EOL;
         }
         sleep(2);
         echo $nh->setPrice($myUSOrders[0], 1, $max_cost, $price) . PHP_EOL;
      }

   } else if ($USprice != 0 && isset($myUSOrders[0]['alive']) &&  $myUSOrders[0]['alive'] == 1) {
      echo "update US miner\n";
      echo $nh->setLimit($myUSOrders[0], 1, $speed_limit) . PHP_EOL;
      if ($USprice > $max_cost) $USprice = $max_cost;
      sleep(2);
      echo $nh->setPrice($myUSOrders[0], 1, $max_cost, $USprice) . PHP_EOL;
      if (isset($myEUOrders[0]['alive']) &&  $myEUOrders[0]['alive'] == 1) {
         sleep(2);
         $price = $max_cost*.75;
         if ($price > $EUprice) {
            $price = $EUprice;
            echo $nh->setLimit($myEUOrders[0], 0, $speed_limit) . PHP_EOL;
         } else {
            echo $nh->setLimit($myEUOrders[0], 0, 0.01) . PHP_EOL;
         }
         sleep(2);
         echo $nh->setPrice($myEUOrders[0], 0, $max_cost, $price) . PHP_EOL;
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
      else break;
   }
	if ($res === false) throw new Exception('Could not connect to any mining pools');
	return json_decode($res, true);
}

?>