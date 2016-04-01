<?php

/**
 * @file classes for tfa_totp
 */

namespace Drupal\tfa_basic\Plugin\TfaValidation;

use Drupal\tfa\Plugin\TfaBasePlugin;
use Drupal\tfa\Plugin\TfaValidationInterface;
use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;
use PHPGangsta_GoogleAuthenticator as GoogleAuthenticator;

/**
 * @TfaValidation(
 *   id = "tfa_basic_totp",
 *   label = @Translation("TFA Toptp Validation"),
 *   description = @Translation("TFA Toptp Validation Plugin")
 * )
 */
class TfaTotp extends TfaBasePlugin implements TfaValidationInterface {

  /**
   * @var PHPGangsta_GoogleAuthenticator
   */
  protected $ga;

  /**
   * @var int
   */
  protected $timeSkew;

  /**
   * @var bool
   */
  protected $alreadyAccepted;

  protected $context;

  /**
   * @copydoc TfaBasePlugin::__construct()
   */
  public function __construct(array $context) {
    parent::__construct($context);
    $this->ga = new GoogleAuthenticator();
    // Allow codes within tolerance range of 3 * 30 second units.
    $this->timeSkew = \Drupal::config('tfa_basic.settings')->get('time_skew');
    // Recommended: set variable tfa_totp_secret_key in settings.php.
    $this->encryptionKey = \Drupal::config('tfa_basic.settings')->get('secret_key');
    $this->alreadyAccepted = FALSE;
    $this->context = $context;
  }

  /**
   * @copydoc TfaBasePlugin::ready()
   */
  public function ready() {
    return ($this->getSeed() !== FALSE);
  }

  /**
   * @copydoc TfaValidationPluginInterface::getForm()
   */
  public function getForm(array $form, FormStateInterface $form_state) {
    $form['code'] = array(
      '#type' => 'textfield',
      '#title' => t('Application verification code'),
      '#description' => t('Verification code is application generated and @length digits long.', array('@length' => $this->codeLength)),
      '#required' => TRUE,
      '#attributes' => array('autocomplete' => 'off'),
    );
    $form['actions']['#type'] = 'actions';
    $form['actions']['login'] = array(
      '#type' => 'submit',
      '#value' => t('Verify'),
    );

    return $form;
  }

  /**
   * @copydoc TfaValidationPluginInterface::validateForm()
   */
  public function validateForm(array $form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    //dpm($values);
    if (!$this->validate($values['code'])) {
      $form_state->setErrorByName('code', t('Invalid application code. Please try again.'));
      if ($this->alreadyAccepted) {
        $form_state->setErrorByName('code', t('Invalid code, it was recently used for a login. Please wait for the application to generate a new code.'));
      }
    }
    else {
      // Store accepted code to prevent replay attacks.
      $this->storeAcceptedCode($values['code']);
    }
  }

  /**
   * @copydoc TfaBasePlugin::validate()
   */
  protected function validate($code) {
    // Strip whitespace.
    $code = preg_replace('/\s+/', '', $code);
    if ($this->alreadyAcceptedCode($code)) {
      $this->isValid = FALSE;
    }
    else {
      // Get OTP seed.
      $seed = $this->getSeed();
      $this->isValid = ($seed && $this->ga->verifyCode($seed, $code, $this->timeSkew));
    }
    return $this->isValid;
  }

  /**
   * @param string $code
   */
  protected function storeAcceptedCode($code) {
    $code = preg_replace('/\s+/', '', $code);
    $hash = hash('sha1', Settings::getHashSalt() . $code);
    db_insert('tfa_accepted_code')
      ->fields(array(
        'uid' => $this->context['uid'],
        'code_hash' => $hash,
        'time_accepted' => REQUEST_TIME,
      ))
      ->execute();
  }

  /**
   * Whether code has recently been accepted.
   *
   * @param string $code
   * @return bool
   */
  protected function alreadyAcceptedCode($code) {
    $hash = hash('sha1', Settings::getHashSalt() . $code);
    $result = db_query(
      "SELECT code_hash FROM {tfa_accepted_code} WHERE uid = :uid AND code_hash = :code",
      array(':uid' => $this->context['uid'], ':code' => $hash)
    )->fetchAssoc();
    if (!empty($result)) {
      $this->alreadyAccepted = TRUE;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get seed for this account.
   *
   * @return string Decrypted account OTP seed or FALSE if none exists.
   */
  protected function getSeed() {
    // Lookup seed for account and decrypt.
    $result = db_query("SELECT seed FROM {tfa_totp_seed} WHERE uid = :uid", array(':uid' => $this->context['uid']))->fetchAssoc();
    if (!empty($result)) {
      $encrypted = base64_decode($result['seed']);
      $seed = $this->decrypt($encrypted);
      if (!empty($seed)) {
        return $seed;
      }
    }
    return FALSE;
  }

  /**
   * Delete users seeds.
   *
   * @return int
   */
  public function deleteSeed() {
    $query = db_delete('tfa_totp_seed')
      ->condition('uid', $this->context['uid']);

    return $query->execute();
  }
}
