<?php

final class PhortuneStripePaymentProvider extends PhortunePaymentProvider {

  const STRIPE_PUBLISHABLE_KEY  = 'stripe.publishable-key';
  const STRIPE_SECRET_KEY       = 'stripe.secret-key';

  public function isEnabled() {
    return $this->getPublishableKey() &&
           $this->getSecretKey();
  }

  public function getName() {
    return pht('Stripe');
  }

  public function getConfigureName() {
    return pht('Add Stripe Payments Account');
  }

  public function getConfigureDescription() {
    return pht(
      'Allows you to accept credit or debit card payments with a '.
      'stripe.com account.');
  }

  public function getPaymentMethodDescription() {
    return pht('Add Credit or Debit Card (US and Canada)');
  }

  public function getPaymentMethodIcon() {
    return celerity_get_resource_uri('/rsrc/image/phortune/stripe.png');
  }

  public function getPaymentMethodProviderDescription() {
    return pht('Processed by Stripe');
  }

  public function getDefaultPaymentMethodDisplayName(
    PhortunePaymentMethod $method) {
    return pht('Credit/Debit Card');
  }

  public function getAllConfigurableProperties() {
    return array(
      self::STRIPE_PUBLISHABLE_KEY,
      self::STRIPE_SECRET_KEY,
    );
  }

  public function getAllConfigurableSecretProperties() {
    return array(
      self::STRIPE_SECRET_KEY,
    );
  }

  public function processEditForm(
    AphrontRequest $request,
    array $values) {

    $errors = array();
    $issues = array();

    if (!strlen($values[self::STRIPE_SECRET_KEY])) {
      $errors[] = pht('Stripe Secret Key is required.');
      $issues[self::STRIPE_SECRET_KEY] = pht('Required');
    }

    if (!strlen($values[self::STRIPE_PUBLISHABLE_KEY])) {
      $errors[] = pht('Stripe Publishable Key is required.');
      $issues[self::STRIPE_PUBLISHABLE_KEY] = pht('Required');
    }

    return array($errors, $issues, $values);
  }

  public function extendEditForm(
    AphrontRequest $request,
    AphrontFormView $form,
    array $values,
    array $issues) {

    $form
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName(self::STRIPE_SECRET_KEY)
          ->setValue($values[self::STRIPE_SECRET_KEY])
          ->setError(idx($issues, self::STRIPE_SECRET_KEY, true))
          ->setLabel(pht('Stripe Secret Key')))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName(self::STRIPE_PUBLISHABLE_KEY)
          ->setValue($values[self::STRIPE_PUBLISHABLE_KEY])
          ->setError(idx($issues, self::STRIPE_PUBLISHABLE_KEY, true))
          ->setLabel(pht('Stripe Publishable Key')));
  }

  public function getConfigureInstructions() {
    return pht(
      "To configure Stripe, register or log in to an existing account on ".
      "[[https://stripe.com | stripe.com]]. Once logged in:\n\n".
      "  - Go to {nav icon=user, name=Your Account > Account Settings ".
      "> API Keys}\n".
      "  - Copy the **Secret Key** and **Publishable Key** into the fields ".
      "above.\n\n".
      "You can either use the test keys to add this provider in test mode, ".
      "or the live keys to accept live payments.");
  }

  public function canRunConfigurationTest() {
    return true;
  }

  public function runConfigurationTest() {
    $root = dirname(phutil_get_library_root('phabricator'));
    require_once $root.'/externals/stripe-php/lib/Stripe.php';

    $secret_key = $this->getSecretKey();
    $account = Stripe_Account::retrieve($secret_key);
  }

  /**
   * @phutil-external-symbol class Stripe_Charge
   * @phutil-external-symbol class Stripe_CardError
   * @phutil-external-symbol class Stripe_Account
   */
  protected function executeCharge(
    PhortunePaymentMethod $method,
    PhortuneCharge $charge) {

    $root = dirname(phutil_get_library_root('phabricator'));
    require_once $root.'/externals/stripe-php/lib/Stripe.php';

    $price = $charge->getAmountAsCurrency();

    $secret_key = $this->getSecretKey();
    $params = array(
      'amount'      => $price->getValueInUSDCents(),
      'currency'    => $price->getCurrency(),
      'customer'    => $method->getMetadataValue('stripe.customerID'),
      'description' => $charge->getPHID(),
      'capture'     => true,
    );

    try {
      $stripe_charge = Stripe_Charge::create($params, $secret_key);
    } catch (Stripe_CardError $ex) {
      // TODO: Fail charge explicitly.
      throw $ex;
    }

    $id = $stripe_charge->id;
    if (!$id) {
      throw new Exception('Stripe charge call did not return an ID!');
    }

    $charge->setMetadataValue('stripe.chargeID', $id);
    $charge->save();
  }

  private function getPublishableKey() {
    return $this
      ->getProviderConfig()
      ->getMetadataValue(self::STRIPE_PUBLISHABLE_KEY);
  }

  private function getSecretKey() {
    return $this
      ->getProviderConfig()
      ->getMetadataValue(self::STRIPE_SECRET_KEY);
  }


/* -(  Adding Payment Methods  )--------------------------------------------- */


  public function canCreatePaymentMethods() {
    return true;
  }


  /**
   * @phutil-external-symbol class Stripe_Token
   * @phutil-external-symbol class Stripe_Customer
   */
  public function createPaymentMethodFromRequest(
    AphrontRequest $request,
    PhortunePaymentMethod $method,
    array $token) {

    $errors = array();

    $root = dirname(phutil_get_library_root('phabricator'));
    require_once $root.'/externals/stripe-php/lib/Stripe.php';

    $secret_key = $this->getSecretKey();
    $stripe_token = $token['stripeCardToken'];

    // First, make sure the token is valid.
    $info = id(new Stripe_Token())->retrieve($stripe_token, $secret_key);

    $account_phid = $method->getAccountPHID();
    $author_phid = $method->getAuthorPHID();

    $params = array(
      'card' => $stripe_token,
      'description' => $account_phid.':'.$author_phid,
    );

    // Then, we need to create a Customer in order to be able to charge
    // the card more than once. We create one Customer for each card;
    // they do not map to PhortuneAccounts because we allow an account to
    // have more than one active card.
    $customer = Stripe_Customer::create($params, $secret_key);

    $card = $info->card;

    $method
      ->setBrand($card->brand)
      ->setLastFourDigits($card->last4)
      ->setExpires($card->exp_year, $card->exp_month)
      ->setMetadata(
        array(
          'type' => 'stripe.customer',
          'stripe.customerID' => $customer->id,
          'stripe.cardToken' => $stripe_token,
        ));

    return $errors;
  }

  public function renderCreatePaymentMethodForm(
    AphrontRequest $request,
    array $errors) {

    $ccform = id(new PhortuneCreditCardForm())
      ->setUser($request->getUser())
      ->setErrors($errors)
      ->addScript('https://js.stripe.com/v2/');

    Javelin::initBehavior(
      'stripe-payment-form',
      array(
        'stripePublishableKey' => $this->getPublishableKey(),
        'formID'               => $ccform->getFormID(),
      ));

    return $ccform->buildForm();
  }

  private function getStripeShortErrorCode($error_code) {
    $prefix = 'cc:stripe:';
    if (strncmp($error_code, $prefix, strlen($prefix))) {
      return null;
    }
    return substr($error_code, strlen($prefix));
  }

  public function validateCreatePaymentMethodToken(array $token) {
    return isset($token['stripeCardToken']);
  }

  public function translateCreatePaymentMethodErrorCode($error_code) {
    $short_code = $this->getStripeShortErrorCode($error_code);

    if ($short_code) {
      static $map = array(
        'error:invalid_number'        => PhortuneErrCode::ERR_CC_INVALID_NUMBER,
        'error:invalid_cvc'           => PhortuneErrCode::ERR_CC_INVALID_CVC,
        'error:invalid_expiry_month'  => PhortuneErrCode::ERR_CC_INVALID_EXPIRY,
        'error:invalid_expiry_year'   => PhortuneErrCode::ERR_CC_INVALID_EXPIRY,
      );

      if (isset($map[$short_code])) {
        return $map[$short_code];
      }
    }

    return $error_code;
  }

  /**
   * See https://stripe.com/docs/api#errors for more information on possible
   * errors.
   */
  public function getCreatePaymentMethodErrorMessage($error_code) {
    $short_code = $this->getStripeShortErrorCode($error_code);
    if (!$short_code) {
      return null;
    }

    switch ($short_code) {
      case 'error:incorrect_number':
        $error_key = 'number';
        $message = pht('Invalid or incorrect credit card number.');
        break;
      case 'error:incorrect_cvc':
        $error_key = 'cvc';
        $message = pht('Card CVC is invalid or incorrect.');
        break;
        $error_key = 'exp';
        $message = pht('Card expiration date is invalid or incorrect.');
        break;
      case 'error:invalid_expiry_month':
      case 'error:invalid_expiry_year':
      case 'error:invalid_cvc':
      case 'error:invalid_number':
        // NOTE: These should be translated into Phortune error codes earlier,
        // so we don't expect to receive them here. They are listed for clarity
        // and completeness. If we encounter one, we treat it as an unknown
        // error.
        break;
      case 'error:invalid_amount':
      case 'error:missing':
      case 'error:card_declined':
      case 'error:expired_card':
      case 'error:duplicate_transaction':
      case 'error:processing_error':
      default:
        // NOTE: These errors currently don't recevive a detailed message.
        // NOTE: We can also end up here with "http:nnn" messages.

        // TODO: At least some of these should have a better message, or be
        // translated into common errors above.
        break;
    }

    return null;
  }

}
