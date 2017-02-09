<?php

class Nicehash {
   private $privateKey = '';    // API Secret
   private $publicKey = '';     // API Key


   public function __construct($priv, $pub) {
      $this->privateKey = $priv;
      $this->publicKey = $pub;

      $result = $this->api_nicehash( array() );

      if( !isset($result['result']['api_version']) ) {
         throw new Exception("Can't Connect to Nichhash API");
      }
      return true;
   }

   private function api_nicehash($req) {
      static $ch = null;
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $url = "https://www.nicehash.com/api?";
      if ($req) { foreach ($req as $rk => $r ) { $url = $url . $rk . '=' . $r . '&'; } }
      curl_setopt($ch, CURLOPT_URL, $url );
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
      $res = curl_exec($ch);
      if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch));
      $result = json_decode($res, true);
      return $result;
   }

   public function getOrders($location) {
      $args = array();
      $args['method'] = 'orders.get';
      $args['location'] = $location;
      $args['algo'] = 22;   // 22 = CryptoNight
      $result = $this->api_nicehash($args);
      if(isset($result['result']['error'])) { echo  "Error: " . $result['result']['error'] . PHP_EOL; return; };
      return $result['result']['orders'];
   }

   public function myOrders($location) {
      $args = array();
      $args['method'] = 'orders.get&my';
      $args['id'] = $this->publicKey;
      $args['key'] = $this->privateKey;
      $args['location'] = $location;
      $args['algo'] = 22;   // 22 = CryptoNight
      $result = $this->api_nicehash($args);
      if(isset($result['result']['error'])) { echo  "Error: " . $result['result']['error'] . PHP_EOL; return; };
      return $result['result']['orders'];
   }

   protected function numworkers($var)
   {
      //echo $var['accepted_speed'] . " " . (boolval($var['accepted_speed']) ? 'true' : 'false') . " ";
      return boolval($var['workers']);
   }

   public function lowestPrice($orders, $depth) {

      $orders = array_filter($orders, array( $this, "numworkers") );
      $orders = array_reverse($orders);
      //print_r(array_slice($orders, 0, 10));

      $workers_sum = 0;
      $price = 0;
      foreach($orders as $order) {
         $workers_sum += $order['workers'];
         if($workers_sum > $depth) {
            return $order['price'];
         }
      }
   }

   public function setLimit($order, $location, $limit = 0.01) {
      if($order['limit_speed'] != $limit){
         $args = array();
         $args['method'] = 'orders.set.limit';
         $args['id'] = $this->publicKey;
         $args['key'] = $this->privateKey;
         $args['location'] = $location;
         $args['algo'] = 22;   // 22 = CryptoNight
         $args['order'] = $order['id'];
         $args['limit'] = $limit;
         $result = $this->api_nicehash($args);
         if(isset($result['result']['error'])) return "Error: " . $result['result']['error'];
         return $result['result']['success'];
      }
   }

   public function setPrice($order, $location, $max_price, $price = 0.01) {
      if($order['price'] < $price){
         $args = array();
         $args['method'] = 'orders.set.price';
         $args['id'] = $this->publicKey;
         $args['key'] = $this->privateKey;
         $args['location'] = $location;
         $args['algo'] = 22;   // 22 = CryptoNight
         $args['order'] = $order['id'];
         $args['price'] = $price;
         $result = $this->api_nicehash($args);
         if(isset($result['result']['error'])) return "Error: " . $result['result']['error'];
         return $result['result']['success'];
      }

      if($order['price'] > $price){
         $args = array();
         $args['method'] = 'orders.set.price.decrease';
         $args['id'] = $this->publicKey;
         $args['key'] = $this->privateKey;
         $args['location'] = $location;
         $args['algo'] = 22;   // 22 = CryptoNight
         $args['order'] = $order['id'];
         $result = $this->api_nicehash($args);
         if(isset($result['result']['error'])) return "Error: " . $result['result']['error'];

         // cancel order and relist if still above max_price
         if( substr($result['result']['success'], 0, 23 ) == "New order price set to:"  ) {
            $return_price = substr($result['result']['success'], 23 );
            if(floatval($return_price) > $max_price && intval($order['workers']) > 0) {
               //print_r($order);
               $order['price'] = $price;
               echo $this->removeOrder($order, $location) . PHP_EOL;
               return $this->createOrder($order, $location) . PHP_EOL;
            }
         }

         return $result['result']['success'];
      }
   }

   public function removeOrder($order, $location) {
      $args = array();
      $args['method'] = 'orders.remove';
      $args['id'] = $this->publicKey;
      $args['key'] = $this->privateKey;
      $args['location'] = $location;
      $args['algo'] = 22;   // 22 = CryptoNight
      $args['order'] = $order['id'];
      sleep(2);
      $result = $this->api_nicehash($args);
      if(isset($result['result']['error'])) { echo  "Error: " . $result['result']['error'] . PHP_EOL; return; };
      return $result['result']['success'];
   }

   public function createOrder($order, $location) {
      $args = array();
      $args['method'] = 'orders.create';
      $args['id'] = $this->publicKey;
      $args['key'] = $this->privateKey;
      $args['location'] = $location;
      $args['algo'] = 22;   // 22 = CryptoNight
      $args['amount'] = $order['btc_avail'];
      $args['price'] = $order['price'];
      $args['limit'] = $order['limit_speed'];
      $args['pool_host'] = $order['pool_host'];
      $args['pool_port'] = $order['pool_port'];
      $args['pool_user'] = $order['pool_user'];
      $args['pool_pass'] = $order['pool_pass'];
      //print_r($order);
      sleep(10); // extra time for order to cancel and balance to reset
      $result = $this->api_nicehash($args);
      if(isset($result['result']['error'])) { echo  "Error: " . $result['result']['error'] . PHP_EOL; return; };
      return $result['result']['success'];
   }

}

?>