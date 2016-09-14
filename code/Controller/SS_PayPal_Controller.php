<?php
class SS_PayPal_Controller extends Page_Controller {

  private static $allowed_actions = [];

  private static $url_handlers = [];

  public function Link($action = null) {
    $home = RootURLController::get_default_homepage_link();
    return Director::baseURL() . $home . '/paypal/';
  }

  public function init() {
    parent::init();
    // if($_SERVER['REQUEST_URI'] == '/paypal/?url=/paypal') {
    //   return $this->redirect($this->Link());
    // }

    // $cart = ShoppingCart::findOrMake();
    // $order = $cart->OpenOrder();

    // if($order->exists()) {
    //   $paymentMethode = $order->PaymentMethode();
    //   $twoSteps = [
    //     'PaymentMethode_Sepa',
    //     'PaymentMethode_Paypal',
    //     'PaymentMethode_Sofort'
    //   ];

    //   if(in_array($paymentMethode->ClassName, $twoSteps) && !$order->Payed) {
    //     $cart->OpenPayment = true;
    //     $cart->OpenOrderID = $order->ID;
    //     $cart->write();
    //   }
      
    //   $this->PayPal();
    // } else {
    //   return $this->redirect($this->ShopLink());
    // }
  }

  public function index() {
    $cart = ShoppingCart::findOrMake();
    
    if($cart->OpenPayment) {
      $order = $cart->OpenOrder();
    } else {
      $order = $cart->OpenOrder();
    }

    $paymentMethode = $order->PaymentMethode();

    $data = [
      'Title' => 'Ihre Bestellung ' . $order->OrderID,
      'ClassName' => 'ShopPayment',
      'Order' => $order,
      'PaymentMethode' => $paymentMethode,
      'MetaTags' => SiteTree::get()->first()->MetaTags()
    ];

    if($paymentMethode->ClassName == 'Payment_Sepa') {
      $data['InfoPage'] = $paymentMethode->InfoPage();
    }

    return $this
      ->customise($data)
      ->renderWith(['ShopPayment', 'Page']);
  }

  public function PayPal() {
    $order = ShoppingCart::findOrMake()->OpenOrder();
    return $order->PaymentMethode()->processPayment($this, $order);
  }
}