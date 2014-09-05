<?php

class TfaBasicSms extends TfaBasePlugin implements TfaValidationPluginInterface, TfaSendPluginInterface {

  protected $client;

  protected $twilioNumber;

  protected $mobileNumber;

  public function __construct(array $context) {
    parent::__construct($context);
    if (!empty($context['validate_context']) && !empty($context['validate_context']['code'])) {
      $this->code = $context['validate_context']['code'];
    }
    $sid = variable_get('tfa_basic_twilio_account_sid', '');
    $token = variable_get('tfa_basic_twilio_account_token', '');
    $this->client = new Services_Twilio($sid, $token);
    $this->twilioNumber = variable_get('tfa_basic_twilio_account_number', '');
    $this->codeLength = 6;
    $this->mobileNumber = '';
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
    if (!empty($this->mobileNumber)) {
      return $this->mobileNumber;
    }
    else {
      $account = user_load($this->context['uid']);
      return tfa_basic_get_mobile_number($account);
    }
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
      $message = $this->client->account->messages->sendMessage($this->twilioNumber, $to, "Login code: " . $code);
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

/**
 * Class TfaBasicSmsSetup
 */
class TfaBasicSmsSetup extends TfaBasicSms implements TfaSetupPluginInterface {

  public function __construct(array $context) {
    parent::__construct($context);
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
  public function getSetupForm(array $form, array &$form_state) {
    $form['code'] = array(
      '#type' => 'textfield',
      '#title' => t('Verification Code'),
      '#required' => TRUE,
      '#description' => t('Enter @length-character code sent to your device.', array('@length' => $this->codeLength)),
    );
    $form['actions']['verify'] = array(
      '#type' => 'submit',
      '#value' => t('Verify'),
    );

    return $form;
  }

  /**
   * @copydoc TfaSetupPluginInterface::validateSetupForm()
   */
  public function validateSetupForm(array $form, array &$form_state) {
    if (!$this->validate($form_state['values']['code'])) {
      $this->errorMessages['code'] = t('Invalid code. Please try again.');
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  /**
   * @copydoc TfaSetupPluginInterface::submitSetupForm()
   */
  public function submitSetupForm(array $form, array &$form_state) {
    // No submission handling required.
    return TRUE;
  }

}