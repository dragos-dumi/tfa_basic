<?php

namespace Drupal\tfa_basic\Plugin\TfaSetup;

use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\tfa_basic\Plugin\TfaValidation\TfaBasicSms;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * @TfaSetup(
 *   id = "tfa_basic_sms_setup",
 *   label = @Translation("TFA SMS Setup"),
 *   description = @Translation("TFA Basic SMS Setup Plugin")
 * )
 */
class TfaBasicSmsSetup extends TfaBasicSms implements TfaSetupInterface {

  public function __construct(array $context, $mobile_number) {
    parent::__construct($context, $mobile_number);
  }

  public function begin() {
    if (empty($this->code)) {
      $this->code = $this->generate();
      if (!$this->sendCode($this->code)) {
        // @todo decide on error text
        $this->errorMessages[''] = t('Unable to deliver code to that number.');
      }
    }
  }

  /**
   * @copydoc TfaSetupPluginInterface::getSetupForm()
   */
  public function getSetupForm(array $form, FormStateInterface &$form_state) {
    $form['sms_code'] = array(
      '#type' => 'textfield',
      '#title' => t('Verification Code'),
      '#required' => TRUE,
      '#description' => t('Enter @length-character code sent to your device.', array('@length' => $this->codeLength)),
    );
    $form['actions']['verify'] = array(
      '#type' => 'submit',
      '#value' => t('Verify and save'),
    );

    return $form;
  }

  /**
   * @copydoc TfaSetupPluginInterface::validateSetupForm()
   */
  public function validateSetupForm(array $form, FormStateInterface &$form_state) {
    if (!$this->validate($form_state['values']['sms_code'])) {
      $this->errorMessages['sms_code'] = t('Invalid code. Please try again.');
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  /**
   * @copydoc TfaSetupPluginInterface::submitSetupForm()
   */
  public function submitSetupForm(array $form, FormStateInterface &$form_state) {
    // No submission handling required.
    return TRUE;
  }

}
