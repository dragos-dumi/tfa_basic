<?php

/**
 * @file class for TFA Basic
 */

namespace Drupal\tfa_basic\Plugin\TfaValidation;

use Drupal\tfa\Plugin\TfaBasePlugin;
use Drupal\tfa\Plugin\TfaValidationInterface;
use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * @TfaValidation(
 *   id = "tfa_basic_recovery_code",
 *   label = @Translation("TFA Recovery Code Validation"),
 *   description = @Translation("TFA Recovery Code Validation Plugin")
 * )
 */
class TfaBasicRecoveryCode extends TfaBasePlugin implements TfaValidationInterface {

  /**
   * @var string
   */
  protected $usedCode;

  public function __construct(array $context) {
    parent::__construct($context);
    // Set in settings.php.
    $this->encryptionKey = \Drupal::config('tfa_basic.settings')->get('secret_key');
  }

  /**
   * @copydoc TfaBasePlugin::ready()
   */
  public function ready() {
    $codes = $this->getCodes();
    return !empty($codes);
  }

  /**
   * @copydoc TfaBasePlugin::getForm()
   */
  public function getForm(array $form, FormStateInterface $form_state) {
    $form['recover'] = array(
      '#type' => 'textfield',
      '#title' => t('Enter one of your recovery codes'),
      '#required' => TRUE,
      '#description' => t('Recovery codes were generated when you first set up TFA. Format: XXX XX XXX'),
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
   * @copydoc TfaBasePlugin::validateForm()
   */
  public function validateForm(array $form, FormStateInterface $form_state) {
    return $this->validate($form_state['values']['recover']);
  }

  /**
   * @copydoc TfaBasePlugin::finalize()
   */
  public function finalize() {
    // Mark code as used.
    if ($this->usedCode) {
      $num = db_update('tfa_recovery_code')
        ->fields(array('used' => REQUEST_TIME))
        ->condition('id', $this->usedCode)
        ->condition('uid', $this->context['uid'])
        ->execute();
      if ($num) {
        watchdog('tfa_basic', 'Used TFA recovery code !id by user !uid', array('!id' => $this->usedCode, '!uid' => $this->context['uid']), WATCHDOG_NOTICE);
      }
    }
  }

  /**
   * Get unused recovery codes.
   *
   * @todo consider returning used codes so validate() can error with
   * appropriate message
   *
   * @return array
   *   Array of codes indexed by ID.
   */
  public function getCodes() {
    // Lookup codes for account and decrypt.
    $codes = array();
    $result = db_query("SELECT id, code FROM {tfa_recovery_code} WHERE uid = :uid AND used = 0", array(':uid' => $this->context['uid']));
    if (!empty($result)) {
      foreach ($result as $data) {
        $encrypted = base64_decode($data->code);
        // trim() prevents extraneous escape characters.
        $code = trim($this->decrypt($encrypted));
        if (!empty($code)) {
          $codes[$data->id] = $code;
        }
      }
    }
    return $codes;
  }

  /**
   * @copydoc TfaBasePlugin::validate()
   */
  protected function validate($code) {
    $this->isValid = FALSE;
    // Get codes and compare.
    $codes = $this->getCodes();
    if (empty($codes)) {
      $this->errorMessages['code'] = t('You have no unused codes available.');
      return FALSE;
    }
    // Remove empty spaces.
    $code = str_replace(' ', '', $code);
    foreach ($codes as $id => $stored) {
      // Remove spaces from stored code.
      if (str_replace(' ', '', $stored) === $code) {
        $this->isValid = TRUE;
        $this->usedCode = $id;
        return $this->isValid;
      }
    }
    $this->errorMessages['code'] = t('Invalid recovery code.');
    return $this->isValid;
  }

}
