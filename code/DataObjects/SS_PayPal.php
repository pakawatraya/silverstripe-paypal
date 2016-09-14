<?php
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\ExecutePayment;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Api\ShippingAddress;

class SS_PayPal extends DataObject implements HiddenClass {
  
  private $apiContext;
  private $items;
  private $shippingAddress;
  private $invoiceAddress;
  private $shippingCost;
  private $invoiceID;
  private $shopTitle;

  function __construct() {
    $this->items = [];
    $this->shopTitle = SiteConfig::current_site_config()->Title;
    $config = $this->config();

    if(Director::isLive()) {
      $clientID = $config->client_id;
      $secret = $config->secret;
    } else {
      $clientID = $config->client_id_sandbox;
      $secret = $config->secret_sandbox;
    }

    $this->apiContext = new \PayPal\Rest\ApiContext(
      new \PayPal\Auth\OAuthTokenCredential($clientID, $secret)
    );
  }

  public function addItem($item) {
    //// $item = [
    ////   'Title' => 'Produktname',
    ////   'Amount' => '10',
    ////   'Sku' => 'Artikelnummer',
    ////   'Price' => '99.95'
    //// ];
    if($this->is_multi_array($item)) {
      $items = $this->items;
      $items = array_merge($items, $item);
    } else {
      $items = $this->items;
      $items[] = $item;
    }

    $this->items = $items;
  }

  public function is_multi_array($array) {
    $rv = array_filter($array, 'is_array');
    if(count($rv) > 0) return true;
    return false;
  }

  public function setAddress($address, $type = 'shipping') {
    //// $address = [
    ////   'Company' => 'StripeForge',
    ////   'FirstName' => 'Benedikt',
    ////   'Surname' => 'Hofstätter',
    ////   'StreetWithNr' => 'Musterweg 123',
    ////   'City' => 'Neumarkt i.d.Opf.',
    ////   'Zip' => '92318',
    ////   'CountryCode' => 'DE'
    //// ];
    if($type == 'shipping') {
      $this->shippingAddress = $address;
    } else if($type == 'invoice') {
      $this->invoiceAddress = $address;
    }
  }

  public function setShippingCost($price) {
    //// $price = '4.90';
    $this->shippingCost = $price;
  }

  public function setInvoiceID($id) {
    //// $id = 'OrderID 123456';
    $this->invoiceID = $id;
  }

  public function processPayment($order) {
    $siteConfig = SiteConfig::current_site_config();
    $apiContext = $this->apiContext;
    $controller = SS_PayPal_Controller::create();

    $r = $controller->request;
    $v = $r->getVars();
    
    if(isset($v['success']) && $order->PaymentTransactionID) {

      if($order->PaymentTransaction()->Status == 'pending' || $order->PaymentTransaction()->Status == 'failed') {
        if($v['success'] == 'true') {
          $paymentTransaction = $order->PaymentTransaction();
          $paymentTransaction->PayerID = $v['PayerID'];
          $paymentTransaction->PaymentID = $v['paymentId'];
          $paymentTransaction->write();

          $this->executePaymet($v);
        } else {
          $paymentTransaction = $order->PaymentTransaction();
          $paymentTransaction->Status = 'failed';
          $paymentTransaction->Message = '<strong>Sie haben den Bezahlvorgang abgebrochen.</strong> Bitte starten Sie diesen erneut.';
          $paymentTransaction->write();
          $controller->redirectBack();
        }
      }
    } else if(!isset($v['success']) && !$order->PaymentTransactionID) {
      $this->submitOrder();
    }
  }

  public function submitOrder() {
    $controller = SS_PayPal_Controller::create();
    $apiContext = $this->apiContext;

    // $paymentTransaction = PaymentTransaction_Paypal::create();
    // $paymentTransaction->Status = 'pending';
    // $paymentTransaction->write();

    // $order->PaymentTransactionID = $paymentTransaction->ID;
    // $order->write();

    $payer = new Payer();
    $payer->setPaymentMethod('paypal');

    // - create paypal items
    $ppItems = [];
    $subTotal = 0;

    foreach($this->items as $item) {
      $ppItem = new Item();
      $ppItem
        ->setName($item['Title'])
        ->setCurrency('EUR')
        ->setQuantity($item['Amount'])
        ->setSku($item['Sku'])
        ->setPrice($item['Price']);

      $ppItems[] = $ppItem;
      
      $sum = $item['Amount'] * $item['Price'];
      $subTotal += $sum;
    }

    // - add shipping address
    $address = $this->shippingAddress;
    $shippingAddress = new ShippingAddress();
    $shippingAddress
      ->setCity($address['City']);
      ->setCountryCode($address['CountryCode']);
      ->setPostalCode($address['Zip']);
      ->setLine1($address['StreetWithNr']);
      ->setRecipientName($address['FirstName'] . ' ' . $address['Surname'] . ' ' . $address['Company']);

    // - add items + shipping address
    $itemList = new ItemList();
    $itemList->setItems($ppItems);
    $itemList->setShippingAddress($shippingAddress);

    // - set costs
    $details = new Details();
    $details
      ->setShipping($this->shippingCost)
      ->setSubtotal($subTotal);
      // ->setTax($order->Vat)

    $amount = new Amount();
    $amount
      ->setCurrency('EUR')
      ->setTotal($subTotal + $this->shippingCost)
      ->setDetails($details);

    // - create transaction
    $transaction = new Transaction();
    $transaction
      ->setAmount($amount)
      ->setItemList($itemList)
      ->setDescription('Bestellung bei ' . $this->shopTitle)
      ->setInvoiceNumber($this->invoiceID);

    // - set redirect
    $baseUrl = rtrim(Director::absoluteBaseURL(), '/') . $controller->Link();
    $redirectUrls = new RedirectUrls();
    $redirectUrls
      ->setReturnUrl("$baseUrl/?success=true")
      ->setCancelUrl("$baseUrl/?success=false");

    // - create payment
    $payment = new Payment();
    $payment->setIntent('sale')
      ->setPayer($payer)
      ->setRedirectUrls($redirectUrls)
      ->setTransactions([$transaction]);

    $request = clone $payment;

    try {
      $payment->create($apiContext);
    } catch (Exception $ex) {
      echo 'error line 210'; die();
      // $response = json_decode($ex->getData());
      // $paymentTransaction->Status = 'failed';
      // $paymentTransaction->Code = $response->name;
      // $paymentTransaction->Message = $response->message;
      // $paymentTransaction->Log = '~|-3-|~' . $ex->getData();
      // $paymentTransaction->write();
      // return $controller->redirectBack();
    }

    $approvalUrl = $payment->getApprovalLink();
    
    // $paymentTransaction->PaymentLink = $approvalUrl;
    // $paymentTransaction->write();

    return $controller->redirect($approvalUrl);
  }

  public function executePaymet($v) {
    $controller = SS_PayPal_Controller::create();
    $siteConfig = SiteConfig::current_site_config();
    $apiContext = $this->apiContext;

    // $paymentTransaction = $order->PaymentTransaction();
    $paymentError = false;
    $paymentId = $v['paymentId'];
    $payment = Payment::get($paymentId, $apiContext);

    $execution = new PaymentExecution();
    $execution->setPayerId($v['PayerID']);

    try {
      $result = $payment->execute($execution, $apiContext);
      
      try {
        $payment = Payment::get($paymentId, $apiContext);
      } catch(Exception $ex) {
        echo 'error 249'; die();
        // $response = json_decode($ex->getData());
        // $paymentTransaction->Status = 'failed';
        // $paymentTransaction->Code = $response->name;
        // $paymentTransaction->Message = $response->message;
        // $paymentTransaction->Log = '~|-2-|~' . $ex->getData();
        // $paymentTransaction->write();
        $paymentError = true;
      }
    } catch(Exception $ex) {
      echo 'error 259'; die();
      // $response = json_decode($ex->getData());
      // $paymentTransaction->Status = 'failed';
      // $paymentTransaction->Code = $response->name;
      // $paymentTransaction->Message = $response->message;
      // $paymentTransaction->Log = '~|-3-|~' . $ex->getData();
      // $paymentTransaction->write();
      $paymentError = true;
    }

    if(!$paymentError) {
      // $cart = ShoppingCart::findOrMake();
      // $cart->OpenPayment = false;
      // $cart->write();

      // $paymentTransaction->Status = 'success';
      // $paymentTransaction->Name = 'VERIFIED';
      // $paymentTransaction->write();

      // $order->Payed = true;
      // $order->PaymentDate = date('Y-m-d H:i:s');
      // $order->write();
    }

    return $controller->redirectBack();
  }
}