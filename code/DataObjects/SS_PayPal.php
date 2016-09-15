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
  private $successMessage;

  function __construct() {
    $this->items = [];
    $this->shippingCost = 0;
    $this->shopTitle = SiteConfig::current_site_config()->Title;
    $this->successMessage = '<strong>Wir bedanken uns für Ihren Einkauf.</stong>';
    
    $config = $this->config();

    Session::set('SS_PayPal_Order', [
      'Status' => false,
      'StatusCode' => false,
      'PayerID' => false,
      'PaymentID' => false,
      'Message' => false,
      'PaymentLink' => false,
      'PaymentDate' => false,
      'SS_PayPal_Object' => false
    ]);

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
    ////   'Street' => 'Musterweg',
    ////   'StreetNr' => '777',
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

  public function setSuccessMessage($msg) {
    //// $msg = '<strong>Wir bedanken uns für Ihren Einkauf</stong><br>...';
    $this->successMessage = $msg;
  }

  public function start() {
    $order = Session::get('SS_PayPal_Order');
    $order['SS_PayPal_Object'] = $this;
    Session::set('SS_PayPal_Order', $order);

    $this->submitOrder();
  }

  public function submitOrder() {
    $order = Session::get('SS_PayPal_Order');
    $controller = Controller::curr();
    $apiContext = $this->apiContext;
    $order['Status'] = 'pending';

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
      ->setCity($address['City'])
      ->setCountryCode($address['CountryCode'])
      ->setPostalCode($address['Zip'])
      ->setLine1($address['Street'] . ' ' . $address['StreetNr'])
      ->setRecipientName($address['FirstName'] . ' ' . $address['Surname'] . ' ' . $address['Company']);

    // - add items + shipping address
    $itemList = new ItemList();
    $itemList
      ->setItems($ppItems)
      ->setShippingAddress($shippingAddress);

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
      ->setReturnUrl($baseUrl . 'receive?success=true')
      ->setCancelUrl($baseUrl . 'receive?success=false');

    // - create payment
    $payment = new Payment();
    $payment->setIntent('sale')
      ->setPayer($payer)
      ->setRedirectUrls($redirectUrls)
      ->setTransactions([$transaction]);

    $request = clone $payment;
    try {
      $payment->create($apiContext);
    } catch(Exception $ex) {
      $response = json_decode($ex->getData());
      $order['Status'] = 'failed';
      $order['StatusCode'] = $response->name;
      $order['Message'] = $response->message;
      Session::set('SS_PayPal_Order', $order);
      return $controller->redirectBack();
    }

    $approvalUrl = $payment->getApprovalLink();
    $order['PaymentLink'] = $approvalUrl;
    Session::set('SS_PayPal_Order', $order);
    return $controller->redirect($approvalUrl);
  }

  public function executePaymet($v, $order) {
    $controller = Controller::curr();
    $apiContext = $this->apiContext;

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
        $response = json_decode($ex->getData());
        $order['Status'] = 'failed';
        $order['StatusCode'] = $response->name;
        $order['Message'] = $response->message;
        Session::set('SS_PayPal_Order', $order);
        $paymentError = true;
      }
    } catch(Exception $ex) {
      $response = json_decode($ex->getData());
      $order['Status'] = 'failed';
      $order['StatusCode'] = $response->name;
      $order['Message'] = $response->message;
      Session::set('SS_PayPal_Order', $order);
      $paymentError = true;
    }

    if(!$paymentError) {
        $order['Status'] = 'success';
        $order['PaymentDate'] = date('Y-m-d H:i:s');
        $order['Message'] = $this->successMessage;
        Session::set('SS_PayPal_Order', $order);
    }

    return $controller->redirect($controller->Link());
  }
}