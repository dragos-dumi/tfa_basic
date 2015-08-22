<?php

namespace Drupal\tfa_basic\Plugin\TfaSetup;

use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\tfa_basic\Plugin\TfaValidation\TfaTotp;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * @TfaSetup(
 *   id = "tfa_basic_totp_setup",
 *   label = @Translation("TFA Toptp Setup"),
 *   description = @Translation("TFA Toptp Setup Plugin")
 * )
 */
class TfaTotpSetup extends TfaTotp implements TfaSetupInterface {

  /**
   * @var string Un-encrypted seed.
   */
  protected $seed;

  /**
   * @var string
   */
  protected $namePrefix;

  /**
   * @copydoc TfaBasePlugin::__construct()
   */
  public function __construct(array $context) {
    parent::__construct($context);
    // Generate seed.
    $this->seed = $this->createSeed();
    $this->namePrefix = \Drupal::config('tfa_basic.settings')->get('name_prefix');
  }

  /**
   * @copydoc TfaSetupPluginInterface::getSetupForm()
   */
  public function getSetupForm(array $form, FormStateInterface &$form_state) {
    $items = [
      \Drupal::l('Google Authenticator (Android/iPhone/BlackBerry)', Url::fromUri('https://support.google.com/accounts/answer/1066447?hl=en'), array('attributes' => array('target'=>'_blank'))),
      \Drupal::l('Authy (Android/iPhone)', Url::fromUri('https://www.authy.com/thefuture#install-now'), array('attributes' => array('target'=>'_blank'))),
      \Drupal::l('Authenticator (Windows Phone)', Url::fromUri('http://www.windowsphone.com/en-us/store/app/authenticator/021dd79f-0598-e011-986b-78e7d1fa76f8'), array('attributes' => array('target'=>'_blank'))),
      \Drupal::l('FreeOTP (Android)', Url::fromUri('https://play.google.com/store/apps/details?id=org.fedorahosted.freeotp'), array('attributes' => array('target'=>'_blank'))),
      \Drupal::l('GAuth Authenticator (desktop)', Url::fromUri('https://github.com/gbraad/html5-google-authenticator'), array('attributes' => array('target'=>'_blank')))
    ];
    $markup = ['#theme' => 'item_list', 'items' => $items, 'title' => t('Install authentication code application on your mobile or desktop device:')];
    $form['apps'] = array(
      '#type' => 'markup',
      '#markup' => \Drupal::service('renderer')->render($markup),
    );
    $form['info'] = array(
      '#type' => 'markup',
      '#markup' => t('<p>The two-factor authentication application will be used during this setup and for generating codes during regular authentication. If the application supports it, scan the QR code below to get the setup code otherwise you can manually enter the text code.</p>'),
    );
    $form['seed'] = array(
      '#type' => 'textfield',
      '#value' => $this->seed,
      '#disabled' => TRUE,
      '#description' => t('Enter this code into your two-factor authentication app or scan the QR code below.'),
    );
    // QR image of seed.
    if (file_exists(drupal_get_path('module', 'tfa_basic') . '/includes/qrcodejs/qrcode.min.js')) {
      $form['qr_image_wrapper']['qr_image'] = array(
        '#markup' => '<div id="tfa-qrcode"></div>',
      );
      $qrdata = 'otpauth://totp/' . $this->accountName() . '?secret=' . $this->seed;
      $form['qr_image_wrapper']['qr_image']['#attached']['library'][] = array('tfa_basic', 'qrcodejs');
      $form['qr_image_wrapper']['qr_image']['#attached']['js'][] = array(
        'data' => 'jQuery(document).ready(function () { new QRCode(document.getElementById("tfa-qrcode"), "' . $qrdata . '");});',
        'type' => 'inline',
        'scope' => 'footer',
        'weight' => 5,
      );
    }
    else {
      $form['qr_image'] = array(
        '#markup' => '<img src="' . $this->getQrCodeUrl($this->seed) .'" alt="QR code for TFA setup">',
      );
    }
    // Include code entry form.
    $form = $this->getForm($form, $form_state);
    $form['actions']['login']['#value'] = t('Verify and save');
    // Alter code description.
    $form['code']['#description'] = t('A verification code will be generated after you scan the above QR code or manually enter the setup code. The verification code is six digits long.');
    return $form;
  }

  /**
   * @copydoc TfaSetupPluginInterface::validateSetupForm()
   */
  public function validateSetupForm(array $form, FormStateInterface &$form_state) {
    if (!$this->validate($form_state->getValue('code'))) {
      $this->errorMessages['code'] = t('Invalid application code. Please try again.');
//      $form_state->setErrorByName('code', $this->errorMessages['code']);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @copydoc TfaBasePlugin::validate()
   */
  protected function validate($code) {
    return $this->ga->verifyCode($this->seed, $code, $this->timeSkew);
  }

  /**
   * @copydoc TfaSetupPluginInterface::submitSetupForm()
   */
  public function submitSetupForm(array $form, FormStateInterface &$form_state) {
    // Write seed for user.
    $this->storeSeed($this->seed);
    return TRUE;
  }

  /**
   * Get a URL to a Google Chart QR image for a seed.
   *
   * @param string $seed
   * @return string URL
   */
  protected function getQrCodeUrl($seed) {
    // Note, this URL is over https but does leak the seed and account
    // email address to Google. See README.txt for local QR code generation
    // using qrcode.js.
    return $this->ga->getQRCodeGoogleUrl($this->accountName(), $seed);
  }

  /**
   * Create OTP seed for account.
   *
   * @return string Seed.
   */
  protected function createSeed() {
    return $this->ga->createSecret();
  }

  /**
   * Save seed for account.
   *
   * @param string $seed Seed.
   */
  protected function storeSeed($seed) {
    // Encrypt seed for storage.
    $encrypted = $this->encrypt($seed);
    // Data is binary so store base64 encoded.
    $record = array(
      'uid' => $this->context['uid'],
      'seed' => base64_encode($encrypted),
      'created' => REQUEST_TIME
    );

    $existing = $this->getSeed();
    if (!empty($existing)) {
      // Update existing seed.
      \Drupal::database()->update('tfa_totp_seed')
        ->condition('uid', $record['uid'])
        ->fields($record)
        ->execute();
    }
    else {
      \Drupal::database()->insert('tfa_totp_seed')
        ->fields($record)
        ->execute();
    }
  }

  /**
   * Get account name for QR image.
   *
   * @return string URL encoded string.
   */
  protected function accountName() {
    /** @var User $account */
    $account =  User::load($this->context['uid']);
    return urlencode($this->namePrefix . '-' . $account->getUsername());
  }

}
