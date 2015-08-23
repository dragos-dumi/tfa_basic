<?php

namespace Drupal\tfa_basic\Plugin\TfaSetup;

use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\tfa_basic\Plugin\TfaValidation\TfaBasicRecoveryCode;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * @TfaSetup(
 *   id = "tfa_basic_recovery_code_setup",
 *   label = @Translation("TFA Recovery Code Setup"),
 *   description = @Translation("TFA Recovery Code Setup Plugin")
 * )
 */
class TfaBasicRecoveryCodeSetup extends TfaBasicRecoveryCode implements TfaSetupInterface {

  /**
   * @var int
   */
  protected $codeLimit;

  /**
   * @var array
   */
  protected $codes;

  public function __construct(array $context) {
    parent::__construct($context);
    $this->codeLimit = \Drupal::config('tfa_basic.settings')->get('codes_amount');
  }

  /**
   * @copydoc TfaSetupPluginInterface::getSetupForm()
   */
  public function getSetupForm(array $form, FormStateInterface &$form_state) {

    $this->codes = $this->generateCodes();

    $form['codes'] = array(
      '#type' => 'item',
      '#title' => t('Your recovery codes'),
      '#description' => t('Print, save, or write down these codes for use in case you are without your application and need to log in.'),
      '#markup' => theme('item_list', array('items' => $this->codes)),
      '#attributes' => array('class' => array('recovery-codes')),
    );
    $form['actions']['save'] = array(
      '#type' => 'submit',
      '#value' => t('Save'),
    );

    return $form;
  }

  /**
   * @copydoc TfaSetupPluginInterface::validateSetupForm()
   */
  public function validateSetupForm(array $form, FormStateInterface &$form_state) {
    // Do nothing, TfaBasicRecoveryCodeSetup has no form inputs.
    return TRUE;
  }

  /**
   * @copydoc TfaSetupPluginInterface::submitSetupForm()
   */
  public function submitSetupForm(array $form, FormStateInterface &$form_state) {
    $this->storeCodes($this->codes);
    return TRUE;
  }

  /**
   * Delete existing codes.
   *
   * @return int
   */
  public function deleteCodes() {
    // Delete any existing codes.
    $num_deleted = db_delete('tfa_recovery_code')
      ->condition('uid', $this->context['uid'])
      ->execute();
    return $num_deleted;
  }

  /**
   * Overide TfaBasePlugin::generate().
   *
   * @return string
   */
  protected function generate() {
    $code = $this->generateBlock(3) . ' ' . $this->generateBlock(2) . ' ' . $this->generateBlock(3);
    return $code;
  }

  /**
   * Generate block of random digits.
   *
   * @param int $length
   * @return string
   */
  protected function generateBlock($length) {
    $block = '';
    do {
      $block .= ord(drupal_random_bytes(1));
    } while (strlen($block) <= $length);

    return substr($block, 0, $length);
  }

  /**
   * Generate recovery codes.
   *
   * Note, these are un-encrypted codes. For any long-term storage be sure to
   * encrypt.
   *
   * @return array
   */
  protected function generateCodes() {
    $codes = array();
    for ($i = 0; $i < $this->codeLimit; $i++) {
      $codes[] = $this->generate();
    }
    return $codes;
  }

  /**
   * Save codes for account.
   *
   * @param array $codes
   */
  protected function storeCodes($codes) {
    $num_deleted = $this->deleteCodes();
    // Encrypt code for storage.
    foreach ($codes as $code) {
      $encrypted = $this->encrypt($code);
      // Data is binary so store base64 encoded.
      $record = array(
        'uid' => $this->context['uid'],
        'code' => base64_encode($encrypted),
        'created' => REQUEST_TIME
      );
      drupal_write_record('tfa_recovery_code', $record);
    }

    $message = 'Saved recovery codes for user !uid';
    if ($num_deleted) {
      $message .= ' and deleted !del old codes';
    }
    watchdog('tfa_basic', $message, array('!uid' => $this->context['uid'], '!del' => $num_deleted), WATCHDOG_INFO);
  }
}
