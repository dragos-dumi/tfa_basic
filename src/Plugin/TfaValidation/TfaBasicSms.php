<?php

use Drupal\tfa\Plugin\TfaBasePlugin;
use Drupal\tfa\Plugin\TfaValidationInterface;
use Drupal\tfa\Plugin\TfaSendInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * @TfaValidation(
 *   id = "tfa_basic_sms",
 *   label = @Translation("TFA SMS Validation"),
 *   description = @Translation("TFA SMS Validation Plugin")
 * )
 */
class TfaBasicSms extends TfaBasePlugin implements TfaValidationInterface, TfaSendInterface {

  protected $client;

  protected $twilioNumber;

  protected $mobileNumber;

  public function __construct(array $context, $mobile_number) {
    parent::__construct($context);
    if (!empty($context['validate_context']) && !empty($context['validate_context']['code'])) {
      $this->code = $context['validate_context']['code'];
    }
    $sid = variable_get('tfa_basic_twilio_account_sid', '');
    $token = variable_get('tfa_basic_twilio_account_token', '');
    $this->client = new Services_Twilio($sid, $token);
    $this->twilioNumber = variable_get('tfa_basic_twilio_account_number', '');
    $this->codeLength = 6;
    $this->messageText = variable_get('tfa_basic_twilio_message_text',
      'Verification code: !code');

    $this->mobileNumber = $mobile_number;
    if (!empty($context['mobile_number'])) {
      $this->mobileNumber = $context['mobile_number'];
    }
  }

  /**
   *
   */
  public function begin() {
    if (!$this->code) {
      $this->code = $this->generate();
      if (!$this->sendCode($this->code)) {
        drupal_set_message(t('Unable to deliver the code. Please contact support.'), 'error');
      }
    }
  }

  public function getForm(array $form, array &$form_state) {
    $form['code'] = array(
      '#type' => 'textfield',
      '#title' => t('Verification Code'),
      '#required' => TRUE,
      '#description' => t('Enter @length-character code sent to your device.', array('@length' => $this->codeLength)),
    );
    $form['actions']['#type'] = 'actions';
    // @todo optionally report on when code was sent/delivered.
    $form['actions']['login'] = array(
      '#type' => 'submit',
      '#value' => t('Verify'),
    );
    $form['actions']['resend'] = array(
      '#type' => 'submit',
      '#value' => t('Resend'),
      '#submit' => array('tfa_form_submit'),
      '#limit_validation_errors' => array(),
    );

    return $form;
  }

  public function validateForm(array $form, array &$form_state) {
    // If operation is resend then do not attempt to validate code.
    if ($form_state['values']['op'] === $form_state['values']['resend']) {
      return TRUE;
    }
    elseif (!parent::validate($form_state['values']['code'])) {
      $this->errorMessages['code'] = t('Invalid code.');
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  public function submitForm(array $form, array &$form_state) {
    // Resend code if pushed.
    if ($form_state['values']['op'] === $form_state['values']['resend']) {
      $this->code = $this->generate();
      if (!$this->sendCode($this->code)) {
        drupal_set_message(t('Unable to deliver the code. Please contact support.'), 'error');
      }
      else {
        drupal_set_message(t('Code resent'));
      }
      return FALSE;
    }
    else {
      return parent::submitForm($form, $form_state);
    }
  }

  /**
   * Return context for this plugin.
   *
   * @return array
   */
  public function getPluginContext() {
    return array(
      'code' => $this->code,
    );
  }

  protected function generate() {
    $characters = '0123456789';
    $string = '';
    $max = strlen($characters) - 1;
    for ($p = 0; $p < $this->codeLength; $p++) {
      $string .= $characters[mt_rand(0, $max)];
    }
    return $string;
  }

  protected function getAccountNumber() {
    return $this->mobileNumber;
  }

  /**
   * Send the code via the client.
   *
   * @param string $code
   * @return bool
   */
  protected function sendCode($code) {
    $to = $this->getAccountNumber();
    try {
      $message = $this->client->account->messages->sendMessage($this->twilioNumber, $to, t($this->messageText, array('!code' => $code)));
      // @todo Consider storing date_sent or date_updated to inform user.
      watchdog('tfa_basic', 'Message !id sent to user !uid on @sent', array(
        '@sent' => $message->date_sent,
        '!id' => $message->sid,
        '!uid' => $this->context['uid'],
      ), WATCHDOG_INFO);
      return TRUE;
    }
    catch (Services_Twilio_RestException $e) {
      // @todo Consider more detailed reporting by mapping Twilio error codes to
      // messages.
      watchdog('tfa_basic', 'Twilio send message error to user !uid @code @link', array(
        '!uid' => $this->context['uid'],
        '@code' => $e->getStatus(),
        '@link' => $e->getInfo(),
        ), WATCHDOG_ERROR);
      return FALSE;
    }
  }
}
