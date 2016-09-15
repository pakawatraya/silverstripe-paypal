<?php
class SS_PayPal_Controller extends Page_Controller {

  private static $allowed_actions = [
    'receive',
    'test'
  ];

  private static $url_handlers = [];

  public function Link($action = null) {
    return Director::baseURL() . 'paypal/';
  }

  public function init() {
    parent::init();
  }

  public function test() {
    if(Director::isDev() && Permission::check(['ADMIN'])) {
      Session::clear('SS_PayPal_Order');
  
      $address = [
        'Company' => 'StripeForge',
        'FirstName' => 'Benedikt',
        'Surname' => 'Hofstätter',
        'Street' => 'Musterweg',
        'StreetNr' => '777',
        'City' => 'Neumarkt i.d.Opf.',
        'Zip' => '92318',
        'CountryCode' => 'DE'
      ];
      
      $item = [
        'Title' => 'Produktname',
        'Amount' => '10',
        'Sku' => 'Artikelnummer',
        'Price' => '99.95'
      ];
  
      $paypal = SS_PayPal::create();
      $paypal->addItem($item);
      $paypal->setAddress($address);
      $paypal->setShippingCost(4.9);
      $paypal->setInvoiceID('asdasd');
      $paypal->start();
    } else {
      Security::permissionFailure();
    }
  }

  public function receive() {
    $apiContext = $this->apiContext;
    $order = Session::get('SS_PayPal_Order');

    $r = $this->request;
    $v = $r->getVars();
    $success = $r->getVar('success');

    if(isset($success) && $order['Status']) {
      if($order['Status'] == 'pending' || $order['Status'] == 'failed') {
        if($success == 'true') {
          $order['PayerID'] = $v['PayerID'];
          $order['PaymentID'] = $v['paymentId'];
          Session::set('SS_PayPal_Order', $order);
          $order['SS_PayPal_Object']->executePaymet($v, $order);
        } else {
          $order['Status'] = 'failed';
          $order['Message'] = 'Sie haben den Bezahlvorgang abgebrochen. Bitte starten Sie diesen erneut.';
          Session::set('SS_PayPal_Order', $order);
          $this->redirect($this->Link());
        }
      }
    }
  }

  public function index() {
    $data = Session::get('SS_PayPal_Order');
    if($data['Status'] == 'success') {
      Session::clear('SS_PayPal_Order');
      $data['Title'] = 'Vielen Dank für Ihre Zahlung';
    } else if($data['Status'] == 'failed') {
      $data['Title'] = 'Fehler beim Bezahlvorgang';
    } else {
      return $this->redirect(Director::baseURL());
    }

    return $this
      ->customise($data)
      ->renderWith(['SS_PayPal', 'Page']);
  }
}
