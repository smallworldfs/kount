<?php

namespace Smallworldfs\Kount\Libraries\Kount\Ris;

use Smallworldfs\Kount\Libraries\Kount\Log\Factory\LogFactory;
use Smallworldfs\Kount\Libraries\Kount\Util\ConfigFileReader;
use Smallworldfs\Kount\Libraries\Kount\Util\Khash;

use Config;

/**
 * @package Kount
 * @subpackage Ris
 */

define('RSA_PUBLIC_KEY', realpath(dirname(__FILE__) .
    DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'rsa.public.key'));

/**
 * Common base class for Kount_Ris_Inquiry and Kount_Ris_Update objects.
 * CURL support must be enabled.
 *
 * @package Kount
 * @subpackage Ris
 * @author Kount <custserv@kount.com>
 * @version $Id$
 * @copyright 2012 Kount, Inc. All Rights Reserved.
 * @see Kount_Ris_Inquiry
 * @see Kount_Ris_Update
 */
abstract class Request {

  /**
   * RIS API Version
   * @var string
   */
  const VERSION = '0630';

  /**
   * RIS data collection for the post
   * @var array
   */
  protected $data = array();

  /**
   * RIS target server URL
   * @var string
   */
  protected $url;

  /**
   * Settings cache
   * @var Kount_Ris_Settings
   */
  protected $settings;

  /**
   * Certificate path and filename
   * @var string
   */
  private $certificate;

  /**
   * Certificate key path and filename
   * @var string
   */
  private $key;

  /**
   * Private key password
   * @var string
   */
  private $password;

  /**
   * API Key used for authentication.
   * Replaces certificates, which are deprecated.
   * @var string
   */
  private $apiKey;

  /**
   * Payment type: Paypal
   * @var string
   */
  const PYPL_TYPE = 'PYPL';

  /**
   * Payment type: Google Checkout
   * @var string
   */
  const GOOG_TYPE = 'GOOG';

  /**
   * Gift card payment type.
   * @var string
   */
  const GIFT_CARD_TYPE = 'GIFT';

  /**
   * Green Dot MoneyPak payment type.
   * @var string
   */
  const GDMP_TYPE = 'GDMP';

  /**
   * No payment type.
   * @var string
   */
  const NONE_TYPE = 'NONE';

  /**
   * Payment type: Credit Card
   * @var string
   */
  const CARD_TYPE = 'CARD';

  /**
   * Payment type: Check
   * @var string
   */
  const CHEK_TYPE = 'CHEK';

  /**
   * Payment type: Bill Me Later
   * @var string
   */
  const BLML_TYPE = 'BLML';

  /**
   * A logger binding.
   * @var Kount_Log_Binding_Logger
   */
  protected $logger;

  /**
   * Message of errors encountered during client side error validation.
   * @var string
   */
  protected $errorMessage;

  /**
   * RIS connection timeout value in seconds.
   * @var int
   */
  protected $connectionTimeout;

  /**
   * Constructor.
   *
   * If no explicit configuration settings are provided we will attempt to
   * read defaults from an ini file using Kount_Util_ConfigFileReader.
   * See Kount_Util_ConfigFileReader for details on setting an alternate path
   * for this file.
   *
   * @param Kount_Ris_Settings $settings Configuration settings
   * @see Kount_Util_ConfigFileReader
   */
  public function __construct ($settings = null) {
    $loggerFactory = LogFactory::getLoggerFactory();
    $this->logger = $loggerFactory->getLogger(__CLASS__);

    if (null === $settings) {
      // try to load settings via ini file
      $configReader = ConfigFileReader::instance();
      $settings = new ArraySettings($configReader->getSettings());
    }
    $this->settings = $settings;

    $this->setMerchantId($this->settings->getMerchantId());
    $this->setVersion(self::VERSION);
    $this->setUrl($this->settings->getRisUrl());
    $this->setApiKey($this->settings->getApiKey());
    $this->setCertificate(
        $this->settings->getX509CertPath(),
        $this->settings->getX509KeyPath(),
        $this->settings->getX509Passphrase());
    $this->setConnectionTimeout($this->settings->getConnectionTimeout());

    // KHASH payment encoding is enabled by default.
    $this->setKhashPaymentEncoding();
  }

  /**
   * Get errors encountered during client side data validation.
   * @return string
   */
  public function getErrorMessage () {
    return $this->errorMessage;
  }

  /**
   * Validate and submit this request for RIS processing.
   *
   * @return Kount_Ris_Response
   * @throws Kount_Ris_Exception Upon a bad repsonse
   */
  public function getResponse () {
    $this->logger->debug(__METHOD__);
    if ($this->isSetKhashPaymentEncoding() && empty($this->data['PTOK'])) {
      $this->setKhashPaymentEncoding(false);
      $this->logger->debug(__METHOD__ . " KHASH payment encoding disabled " .
          "due to empty payment token. Request mode [" . $this->data['MODE'] .
          "]");
    }

    // validate first
    $errors = Validate::validate($this->data);
    if (count($errors) > 0) {
      $errorMsg = "";
      foreach ($errors as $error) {
        $errorMsg .= $error . "\n";
      }
      $this->errorMessage = $errorMsg;
      throw new ValidationException($errorMsg);
    }

    $this->logger->debug(__METHOD__ . " RIS endpoint URL: [{$this->url}]");
    // Initialize CURL settings
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLINFO_SSL_VERIFYRESULT, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, $this->connectionTimeout);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    # Only DEBUG Mode
    //curl_setopt($ch, CURLOPT_VERBOSE, 1);
    //$verbose = fopen('/path/to/project/logs/curl.log', 'w+');
    //curl_setopt($ch, CURLOPT_STDERR, $verbose);
    # ----------

    if(Config::get('kount.CACERT') != ''){
      curl_setopt($ch, CURLOPT_CAINFO, Config::get('kount.CACERT'));
    }

    // try API key authentication first, then fall back to certificates
    // which are deprecated.
    if ($this->apiKey != "") {
      curl_setopt($ch, CURLOPT_HTTPHEADER,
        array("X-Kount-Api-Key: {$this->apiKey}"));
    } else {
      // Set RIS certificate in CURL.
      // If certificate is a .pk12 file then it must be converted to PEM format.
      // The UNIX command line tool 'openssl' converts .pk12 to PEM.
      // openssl pkcs12 -nocerts -in exported.p12 -out key.pem.
      // openssl pkcs12 -clcerts -nokeys -in exported.p12 -out cert.pem
      if (isset($this->certificate) && isset($this->key)) {
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, "PEM");
        curl_setopt($ch, CURLOPT_SSLCERT, $this->certificate);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->key);
        curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->password);
      } else {
        $this->logger->warn(__METHOD__ .
            " No RIS client authentication certificate set.");
      }
    }

    // Construct the POST
    $payload = array();
    foreach ($this->data as $key => $value) {
      // OK to pass empty strings to backend, for consistency with other
      // SDK languages.
      $payload[] = urlencode($key) . '=' . urlencode($value);
      $value = ('PTOK' == $key && !empty($value)) ?
          'payment token hidden' : $value;
      $this->logger->debug(__METHOD__ . " [{$key}]={$value}");
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $payload));

    $this->logger->debug(__METHOD__ . " Posting to RIS");
    // Call the RIS server and get the response
    $output = curl_exec($ch);

    if (curl_errno($ch)) {
      $result = curl_error($ch);
      $this->logger->error(__METHOD__ . " An error occurred posting to RIS. " .
        "Curl error (".curl_errno($ch).") [$result]");
      throw new Exception($result);
    }
    curl_close($ch);

    $this->logger->debug(__METHOD__ . " Raw RIS response:\n {$output}");

    return new Response($output);
  } //end getResponse

  /**
   * Set a parameter for the request
   *
   * @param string $key The key for the parameter
   * @param string $value The value for the parameter
   * @return this
   */
  public function setParm ($key, $value) {
    $this->data["{$key}"] = $value;
    return $this;
  }

  /**
   * Set the mode
   *
 MONTH  * @param string $mode RIS mode
   * @return this
   */
  abstract public function setMode ($mode);

  /**
   * Set the merchant id
   *
   * @param int $merchantId Merchant Id
   * @return this
   */
  protected function setMerchantId ($merchantId) {
    $this->data['MERC'] = $merchantId;
    return $this;
  }

  /**
   * Set the merchant gateway's customer id for Kount Central
   *
   * @param string $customerId Customer Id
   * @return this
   */
  public function setKcCustomerId ($customerId) {
    $this->data['CUSTOMER_ID'] = $customerId;
    return $this;
  }

  /**
   * Get the maximum number of seconds for RIS connection function to timeout.
   * @param int $timeout Number of seconds to timeout
   * @return this
   */
  public function setConnectionTimeout ($timeout) {
    $this->connectionTimeout = $timeout;
    return $this;
  }

  /**
   * Set the version number
   *
   * @param string $version Version number
   * @return this
   */
  public function setVersion ($version) {
    if (is_int($version)) {
      $this->logger->error(__METHOD__ . " Invalid version number [{$version}]");
      throw new IllegalArgumentException("Version must be a string");
    }
    $this->data['VERS'] = $version;
    return $this;
  }

  /**
   * Set the session id
   *
   * @param string $sessionId Id of the current session
   * @return this
   */
  public function setSessionId ($sessionId) {
    $this->data['SESS'] = $sessionId;
    return $this;
  }

  /**
   * Set the order number
   *
   * @param string $orderNumber Merchant unique order number
   * @return this
   */
  public function setOrderNumber ($orderNumber) {
    $this->data['ORDR'] = $orderNumber;
    return $this;
  }

  /**
   * Set the mack
   *
   * @param string $mack Merchant acknowledgement
   * @return this
   */
  public function setMack ($mack) {
    $this->data['MACK'] = $mack;
    return $this;
  }

  /**
   * Set the auth
   *
   * @param string $auth Auth status by issuer (A/D)
   * @return this
   */
  public function setAuth ($auth) {
    $this->data['AUTH'] = $auth;
    return $this;
  }

  /**
   * Set the avsz
   *
   * @param string $avsz Bankcard AVS zip code reply (M/N/X)
   * @return this
   */
  public function setAvsz ($avsz) {
    $this->data['AVSZ'] = $avsz;
    return $this;
  }

  /**
   * Set the avst
   *
   * @param string $avst Bankcard AVS street address reply (M/N/X)
   * @return this
   */
  public function setAvst ($avst) {
    $this->data['AVST'] = $avst;
    return $this;
  }

  /**
   * Set the cvvr
   *
   * @param string $cvvr Bankcard CVV/CVC/CVV2 reply (M/N/X)
   * @return this
   */
  public function setCvvr ($cvvr) {
    $this->data['CVVR'] = $cvvr;
    return $this;
  }

  /**
   * Set the RIS target server URL
   *
   * @param string $url Website URL
   * @return this
   */
  public function setUrl ($url) {
    $this->url = $url;
    return $this;
  }

  /**
   * The API key for authentication. Use this in favor of certificates, which
   * have been deprecated.
   * @return this
   */
  public function setApiKey ($key) {
    $this->apiKey = $key;
    return $this;
  }

  /**
   * Set certificate information
   *
   * @param string $certificate Path and file to the PEM certificate
   * @param string $key Path and file to the PEM key
   * @param string $password Password for the private key
   * @return this
   */
  public function setCertificate ($certificate, $key, $password) {
    $this->certificate = $certificate;
    $this->key = $key;
    $this->password = $password;
    return $this;
  }

  /**
   * Set a Green Dot MoneyPak payment.
   * @param string $paymentId Payment ID number
   * @return this
   */
  public function setGreenDotMoneyPakPayment ($paymentId) {
    $this->data['PTYP'] = self::GDMP_TYPE;
    return $this->setPaymentToken($paymentId);
  }

  /**
   * Set no payment.
   * @return this
   */
  public function setNoPayment () {
    $this->data['PTYP'] = self::NONE_TYPE;
    $this->data['PTOK'] = null;
    return $this;
  }

  /**
   * Set a PayPal payment.
   *
   * @param string $payPalId PayPal payer id
   * @return this
   */
  public function setPayPalPayment ($payPalId) {
    $this->data['PTYP'] = self::PYPL_TYPE;
    return $this->setPaymentToken($payPalId);
  }

  /**
   * Set a google payment.
   *
   * @param string $googleId Google Checkout id
   * @return this
   */
  public function setGooglePayment ($googleId) {
    $this->data['PTYP'] = self::GOOG_TYPE;
    return $this->setPaymentToken($googleId);
  }

  /**
   * Set a gift card payment.
   * @param string $giftCardNumber Gift card number
   * @return this
   */
  public function setGiftCardPayment ($giftCardNumber) {
    $this->data['PTYP'] = self::GIFT_CARD_TYPE;
    return $this->setPaymentToken($giftCardNumber);
  }

  /**
   * Set a card payment.
   *
   * @param string $cardNumber Raw card number
   * @return this
   */
  public function setCardPayment ($cardNumber) {
    $this->data['PTYP'] = self::CARD_TYPE;
    return $this->setPaymentToken($cardNumber);
  }

  /**
   * Set a check payment.
   *
   * @param string $micr Micr line on the check
   * @return this
   */
  public function setCheckPayment ($micr) {
    $this->data['PTYP'] = self::CHEK_TYPE;
    return $this->setPaymentToken($micr);
  }

  /**
   * Set a bill-me-later payment.
   *
   * @param string $blmlId Bill-me-later id
   * @return this
   */
  public function setBillMeLaterPayment ($blmlId) {
    $this->data['PTYP'] = self::BLML_TYPE;
    return $this->setPaymentToken($blmlId);
  }

  /**
   * Set KHASH payment encoding.
   * @param boolean $enabled Default to TRUE to enable KHASH payment encoding.
   * @return this
   */
  public function setKhashPaymentEncoding ($enabled = true) {
    if ($enabled) {
      $this->data["PENC"] = "KHASH";
    } else {
      $this->data["PENC"] = "";
    }
    return $this;
  }

  /**
   * Check if KHASH payment encoding has been set.
   * @return boolean TRUE when set.
   */
  protected function isSetKhashPaymentEncoding () {
    return array_key_exists("PENC", $this->data) &&
        "KHASH" == $this->data["PENC"];
  }

  /**
   * Set the payment token.
   * @param string $token Payment token
   * @return this
   */
  protected function setPaymentToken ($token) {
    if (!empty($token) && empty($this->data['LAST4'])) {
      if (mb_strlen($token) >= 4) {
        $this->data['LAST4'] = mb_substr($token, mb_strlen($token) - 4);
      } else {
        $this->data['LAST4'] = $token;
      }
    }

    if ($this->isSetKhashPaymentEncoding()) {
      $token = (self::GIFT_CARD_TYPE == $this->data['PTYP']) ?
          Khash::hashGiftCard($this->data['MERC'], $token) :
          Khash::hashPaymentToken($token);
    }
    $this->data['PTOK'] = $token;
    return $this;
  }

  /**
   * Set the last 4 characters on the payment token.
   * @param string $last4 Last 4 characters of payment token
   * @return this
   */
  public function setPaymentTokenLast4 ($last4) {
    $this->data['LAST4'] = $last4;
    return $this;
  }

  /**
   * Set the payment type and payment token. Payment token must be raw,
   * i.e. NOT Khashed.
   *
   * @param string $paymentType The payment type. See sdk documentation for accepted payment types
   * @param string $paymentToken The payment token
   * @return this
   */
  public function setPayment ($paymentType, $paymentToken) {
    $this->logger->debug(__METHOD__);
    if (!empty($paymentToken) && empty($this->data['LAST4'])) {
      if (mb_strlen($paymentToken) >= 4) {
        $this->data['LAST4'] =
          mb_substr($paymentToken, mb_strlen($paymentToken) - 4);
      } else {
        $this->data['LAST4'] = $paymentToken;
      }
    }

    $token = (self::GIFT_CARD_TYPE == $paymentType) ?
      Khash::hashGiftCard($this->data['MERC'], $paymentToken) :
      Khash::hashPaymentToken($paymentToken);

    $this->data['PTYP'] = $paymentType;
    $this->data['PTOK'] = $token;
    return $this;
  }

} // end Kount_Ris_Request
