<?php

/**
 * @param $account
 * @return string
 */
function tfa_basic_overview($form, &$form_state, $account) {

  $output['info'] = array(
    '#type' => 'markup',
    '#markup' => '<p>' . t('Two-factor authentication (TFA) provides additional security for your account. With TFA enabled, you log in to the site with a uniquely generated code in addition to your username and password.') . '</p>',
  );
  $form_state['storage']['account'] = $account;
  $user_tfa = tfa_basic_get_tfa_data($account);
  $enabled = isset($user_tfa['status']) && $user_tfa['status'] ? TRUE : FALSE;

  if (!empty($user_tfa)) {
    if ($enabled) {
      $status_text = t('Status: <strong>TFA enabled</strong>, set !time', array('!time' => format_date($user_tfa['saved'])));
    }
    else {
      $status_text = t('Status: <strong>TFA disabled</strong>, set !time', array('!time' => format_date($user_tfa['saved'])));
    }
    $output['status'] = array(
      '#type' => 'markup',
      '#markup' => '<p>' . $status_text . '</p>',
    );
  }

  // Application setup.
  $output['app'] = array(
    '#type' => 'fieldset',
    '#title' => t('TFA application'),
    '#description' => t('Generate TFA codes for log in from a mobile or desktop application.'),
  );
  $setup_text = !$enabled ? t('Set up application') : t('Reset application');
  $output['app']['link'] = array(
    '#type' => 'markup',
    '#markup' => l($setup_text, 'user/' . $account->uid . '/security/tfa/app-setup'),
  );

  // SMS setup.
  $phone_field = variable_get('tfa_basic_phone_field', '');
  if ($enabled && $phone_field !== '' && in_array('tfa_basic_sms', variable_get('tfa_fallback_plugins', array()))) {
    $output['sms'] = array(
      '#type' => 'fieldset',
      '#title' => t('SMS fallback'),
      '#description' => t('Receive TFA code via SMS if you are are unable to use mobile application during log in.'),
    );
    $mobile_number = tfa_basic_get_mobile_number($account);
    if (!empty($user_tfa['data']['sms']) && $mobile_number !== FALSE) {
      $output['sms']['number'] = array(
        '#type' => 'markup',
        '#markup' => '<p>' . t('Optional delivery to @number', array('@number' => tfa_basic_format_number($mobile_number))) . '</p>',
      );
    }
    if (empty($user_tfa['data']['sms'])) {
      $sms_text = t('Set up SMS delivery');
    }
    else {
      $sms_text = t('Reset SMS delivery');
    }
    $output['sms']['link'] = array(
      '#type' => 'markup',
      '#markup' => l($sms_text, 'user/' . $account->uid . '/security/tfa/sms-setup'),
    );
  }

  // Trusted browsers.
  if ($enabled && in_array('tfa_basic_trusted_device', variable_get('tfa_login_plugins', array()))) {
    $trusted_device = new TfaTrustedDeviceSetup(array('uid' => $account->uid));
    $trusted_browsers = array();
    foreach ($trusted_device->getTrustedDevices() as $device) {
      $vars = array(
        '!set' => format_date($device['created']),
        '@browser' => $device['name'],
        '!time' => format_date($device['last_used']),
      );
      if (empty($device['last_used'])) {
        $message = t('@browser, set !set', $vars);
      }
      else {
        $message = t('@browser, set !set, last used !time', $vars);
      }
      $trusted_browsers[] = $message;
    }
    $time = variable_get('tfa_basic_trust_cookie_expiration', 3600 * 24 * 30) / (3600 * 24);
    $output['trust'] = array(
      '#type' => 'fieldset',
      '#title' => t('Trusted browsers'),
      '#description' => t('Browsers that will not require TFA during log in for !time days from first set.', array('!time' => $time)),
    );
    if (!empty($trusted_browsers)) {
      $output['trust']['list'] = array(
        '#type' => 'markup',
        '#markup' => theme('item_list', array('items' => $trusted_browsers)),
      );
    }
    $output['trust']['link'] = array(
      '#type' => 'markup',
      '#markup' => l(t('Set trusted browsers'), 'user/' . $account->uid . '/security/tfa/trusted-browsers'),
    );
  }

  // Recovery codes.
  if ($enabled && in_array('tfa_basic_recovery_code', variable_get('tfa_fallback_plugins', array()))) {
    $recovery = new TfaBasicRecoveryCodeSetup(array('uid' => $account->uid));
    $output['recovery'] = array(
      '#type' => 'fieldset',
      '#title' => t('Recovery codes'),
      '#description' => t('Pre-generated, one-time-use codes that allow log in instead of the TFA application code.'),
    );
    $recovery_codes = $recovery->getCodes();
    if (empty($recovery_codes)) {
      $codes_text = t('Get recovery codes');
    }
    else {
      $output['recovery']['list'] = array(
        '#type' => 'markup',
        '#markup' => '<p>' . l(t('View unused recovery codes'), 'user/' . $account->uid . '/security/tfa/recovery-codes-list') . '</p>',
      );
      $codes_text = t('Get new recovery codes');
    }
    $output['recovery']['link'] = array(
      '#type' => 'markup',
      '#markup' => l($codes_text, 'user/' . $account->uid . '/security/tfa/recovery-codes'),
    );
  }

  if ($enabled) {
    $output['disable'] = array(
      '#type' => 'markup',
      '#markup' => '<p>' . l(t('Disable TFA'), 'user/' . $account->uid . '/security/tfa/disable') . '</p>',
    );
  }

  return $output;
}

function tfa_basic_disable_form($form, &$form_state, $account) {
  global $user;

  $form_state['storage']['account'] = $account;

  if ($account->uid != $user->uid && user_access('administer users')) {
    $preamble_desc = t('Are you sure you want to disable TFA on account %name?', array('%name' => $account->name));
    $notice_desc = t('TFA settings and data will be lost. %name can re-enable TFA again from their profile.', array('%name' => $account->name));
    if (user_access('require tfa', $account)) {
      drupal_set_message(t("This account is required to have TFA enabled per the 'require TFA' permission on one of their roles. Disabling TFA will remove their ability to log back into the site. If you continue, consider also removing the role so they can authenticate and setup TFA again."), 'warning');
    }
  }
  else {
    $preamble_desc = t('Are you sure you want to disable your two-factor authentication setup?');
    $notice_desc = t("Your settings and data will be lost. You can re-enable two-factor authentication again from your profile.");
    if (user_access('require tfa', $account)) {
      drupal_set_message(t('Your account must have at least one two-factor authentication method enabled. Continuing will disable your ability to log back into this site.'), 'warning');
      $notice_desc = t('Your settings and data will be lost and you will be unable to log back into the site. To regain access contact a site administrator.');
    }
  }
  $form['preamble'] = array(
    '#prefix' => '<p class="preamble">',
    '#suffix' => '</p>',
    '#markup' => $preamble_desc,
  );
  $form['notice'] = array(
    '#prefix' => '<p class="preamble">',
    '#suffix' => '</p>',
    '#markup' => $notice_desc,
  );

  $form['account']['current_pass'] = array(
    '#type' => 'password',
    '#title' => t('Confirm your current password'),
    '#description_display' => 'before',
    '#size' => 25,
    '#weight' => -5,
    '#attributes' => array('autocomplete' => 'off'),
    '#required' => TRUE,
  );
  $form['account']['mail'] = array(
    '#type' => 'value',
    '#value' => $account->mail,
  );
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Disable'),
  );
  $form['actions']['cancel'] = array(
    '#type' => 'submit',
    '#value' => t('Cancel'),
    '#limit_validation_errors' => array(),
    '#submit' => array('tfa_basic_disable_form_submit'),
  );

  return $form;
}

/**
 * Disable form validate.
 */
function tfa_basic_disable_form_validate($form, &$form_state) {
  global $user;
  $account = $form_state['storage']['account'];
  // Allow administrators to disable TFA for another account.
  if ($account->uid != $user->uid && user_access('administer users')) {
    $account = $user;
  }
  // Check password. (from user.module user_validate_current_pass()).
  require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');
  $current_pass = user_check_password($form_state['values']['current_pass'], $account);
  if (!$current_pass) {
    form_set_error('current_pass', t("Incorrect password."));
  }
}

/**
 * Disable form submit.
 */
function tfa_basic_disable_form_submit($form, &$form_state) {
  $account = $form_state['storage']['account'];
  if ($form_state['values']['op'] === $form_state['values']['cancel']) {
    drupal_set_message(t('TFA disable canceled.'));
    $form_state['redirect'] = 'user/' . $account->uid . '/security/tfa';
    return;
  }
  $params = array('account' => $account);
  tfa_basic_setup_save_data($account, array('status' => FALSE));
  // Delete TOTP code.
  $totp = new TfaTotp(array('uid' => $account->uid));
  $totp->deleteSeed();
  // Delete recovery codes.
  $recovery = new TfaBasicRecoveryCodeSetup(array('uid' => $account->uid));
  $recovery->deleteCodes();
  // Delete trusted devices.
  $trusted = new TfaTrustedDeviceSetup(array('uid' => $account->uid));
  $trusted->deleteTrustedDevices();

  watchdog('tfa_basic', 'TFA disabled for user @name UID !uid', array(
    '@name' => $account->name,
    '!uid' => $account->uid,
  ), WATCHDOG_NOTICE);

  // E-mail account to inform user that it has been disabled.
  drupal_mail('tfa_basic', 'tfa_basic_disabled_configuration', $account->mail, user_preferred_language($account), $params);

  drupal_set_message(t('TFA has been disabled.'));
  $form_state['redirect'] = 'user/' . $account->uid . '/security/tfa';
}

/**
 * TFA setup form router.
 */
function tfa_basic_setup_form($form, &$form_state, $account, $method = 'app_setup') {
  global $user;

  $form['account'] = array(
    '#type' => 'value',
    '#value' => $account,
  );
  $tfa_data = tfa_basic_get_tfa_data($account);

  // Do not continue if this isn't app setup and the app isn't already set.
  if (empty($tfa_data['status']) && $method != 'app_setup') {
    drupal_set_message(t('Set up TFA application before continuing with additional configurations.'), 'warning');
    drupal_goto('user/' . $account->uid . '/security/tfa');
  }

  if (empty($form_state['storage'])) {

    // Allow administrators to change TFA settings for another account.
    if ($account->uid != $user->uid && user_access('administer users')) {
      $current_pass_description = t('Enter your current password to alter TFA settings for account %name.', array('%name' => $account->name));
    }
    else {
      $current_pass_description = t('Enter your current password to continue.');
    }
    $form['current_pass'] = array(
      '#type' => 'password',
      '#title' => t('Current password'),
      '#size' => 25,
      '#required' => TRUE,
      '#description' => $current_pass_description,
      '#attributes' => array('autocomplete' => 'off'),
    );
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Confirm'),
    );
    $form['cancel'] = array(
      '#type' => 'submit',
      '#value' => t('Cancel'),
      '#limit_validation_errors' => array(),
      '#submit' => array('tfa_basic_setup_form_submit'),
    );
  }
  else {
    // Support multi-step TFA plugins triggered in tfa_basic_setup_form_submit.
    if (isset($form_state['storage']['method'])) {
      $method = $form_state['storage']['method'];
    }
    $context = array('uid' => $account->uid);
    switch ($method) {
      case 'app_setup':
        drupal_set_title(t('TFA setup - Application'));
        $form_state['storage']['methods'][] = $method;
        $setup_plugin = new TfaTotpSetup($context);
        $tfa_setup = new TfaSetup($setup_plugin, $context);

        if (!empty($tfa_data)) {
          $form['disclaimer'] = array(
            '#type' => 'markup',
            '#markup' => '<p>' . t('Note: You should delete the old account in your mobile or desktop app before adding this new one.') . '</p>',
          );
        }
        $form = $tfa_setup->getForm($form, $form_state);
        $form_state['storage'][$method] = $tfa_setup;
        break;

      case 'trust_device':
        drupal_set_title(t('TFA setup - Trusted browsers'));
        $form_state['storage']['methods'][] = $method;
        $setup_plugin = new TfaTrustedDeviceSetup($context);
        $tfa_setup = new TfaSetup($setup_plugin, $context);
        $form = $tfa_setup->getForm($form, $form_state);
        $form_state['storage'][$method] = $tfa_setup;
        break;

      case 'recovery_codes':
        drupal_set_title(t('TFA setup - Recovery codes'));
        $form_state['storage']['methods'][] = $method;
        $setup_plugin = new TfaBasicRecoveryCodeSetup($context);
        $tfa_setup = new TfaSetup($setup_plugin, $context);
        $form = $tfa_setup->getForm($form, $form_state);
        $form_state['storage'][$method] = $tfa_setup;
        break;

      case 'recovery_codes_list':
        $recovery = new TfaBasicRecoveryCodeSetup(array('uid' => $account->uid));
        $codes = $recovery->getCodes();

        $output = theme('item_list', array('items' => $codes));
        $output .= l(t('Return to account TFA overview'), 'user/' . $account->uid . '/security/tfa');
        $form['output'] = array(
          '#type' => 'markup',
          '#markup' => $output,
        );
        // Return early.
        return $form;

      case 'sms_setup':
        drupal_set_title(t('TFA setup - SMS'));
        // First step is number entry.
        if (empty($form_state['storage'][$method])) {
          $default_number = tfa_basic_get_mobile_number($account);
          $form['sms_number'] = array(
            '#type' => 'textfield',
            '#title' => t('Mobile phone number'),
            '#required' => TRUE,
            '#description' => t('Enter your mobile phone number that can receive SMS codes. A code will be sent to this number for validation.'),
            '#default_value' => $default_number ?: '',
          );
          $phone_field = variable_get('tfa_basic_phone_field', '');
          if (!empty($phone_field)) {
            // Report that this is an account field.
            $field = field_info_instance('user', $phone_field, 'user');
            $form['sms_number']['#description'] .= ' ' . t('This number is stored on your account under field %label.', array('%label' => $field['label']));
          }
          $form['send'] = array(
            '#type' => 'submit',
            '#value' => t('Send SMS'),
          );
          $form_state['storage']['methods'][] = $method;
          if (!empty($tfa_data['data']['sms'])) {
            // Provide disable SMS option.
            $form['actions']['sms_disable'] = array(
              '#type' => 'submit',
              '#value' => t('Disable SMS delivery'),
              '#limit_validation_errors' => array(),
              '#submit' => array('tfa_basic_setup_form_submit'),
            );
          }
        }
        // Then validate by sending an SMS.
        else {
          $number = tfa_basic_format_number($form_state['storage']['sms_number']);
          drupal_set_message(t("A code was sent to @number. It may take up to a minute for its arrival.", array('@number' => $number)));
          $tfa_setup = $form_state['storage'][$method];
          $form = $tfa_setup->getForm($form, $form_state);
          if (in_array('app_setup', $form_state['storage']['methods'])) {
            drupal_set_message(t("If the code does not arrive or you entered the wrong number skip this step to continue without SMS delivery. You can enable it after completing the rest of TFA setup."));
          }
          else {
            $form['code']['#description'] .= ' ' . l(t('If the code does not arrive or you entered the wrong number click here to start over.'), 'user/' . $account->uid . '/security/tfa/sms-setup');
          }

          $form_state['storage'][$method] = $tfa_setup;
        }
        break;

      default:
        break;
    }
    // Provide skip button for optional TFA plugins.
    if (isset($form_state['storage']['methods']) && count($form_state['storage']['methods']) > 1) {
      $form['actions']['skip'] = array(
        '#type' => 'submit',
        '#value' => t('Skip'),
        '#limit_validation_errors' => array(),
        '#submit' => array('tfa_basic_setup_form_submit'),
      );
    }
    // Provide cancel button on first step or single steps.
    else {
      $form['actions']['cancel'] = array(
        '#type' => 'submit',
        '#value' => t('Cancel'),
        '#limit_validation_errors' => array(),
        '#submit' => array('tfa_basic_setup_form_submit'),
      );
    }
    $form_state['storage']['method'] = $method;
  }

  return $form;

}

/**
 * Setup form validate.
 */
function tfa_basic_setup_form_validate($form, &$form_state) {
  global $user;
  $account = $form['account']['#value'];
  if (isset($form_state['values']['current_pass'])) {
    // Allow administrators to change TFA settings for another account.
    if ($account->uid != $user->uid && user_access('administer users')) {
      $account = $user;
    }
    // Check password. (from user.module user_validate_current_pass()).
    require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');
    $current_pass = user_check_password($form_state['values']['current_pass'], $account);
    if (!$current_pass) {
      form_set_error('current_pass', t("Incorrect password."));
    }
    return;
  }
  elseif (isset($form_state['values']['cancel']) && $form_state['values']['op'] === $form_state['values']['cancel']) {
    return;
  }
  // Handle first step of SMS setup.
  elseif (isset($form_state['values']['sms_number'])) {
    // Validate number.
    $number = $form_state['values']['sms_number'];
    $number_errors = tfa_basic_valid_number($number);
    if (!empty($number_errors)) {
      foreach ($number_errors as $error) {
        form_set_error('number', $error);
      }
    }
    return;
  }
  elseif (!empty($form_state['storage']['method'])) {
    $method = $form_state['storage']['method'];
    $tfa_setup = $form_state['storage'][$method];
    if (!$tfa_setup->validateForm($form, $form_state)) {
      foreach ($tfa_setup->getErrorMessages() as $element => $message) {
        form_set_error($element, $message);
      }
    }
  }
}

/**
 * Setup form submit.
 */
function tfa_basic_setup_form_submit($form, &$form_state) {
  $account = $form['account']['#value'];

  // Cancel button.
  if (isset($form_state['values']['cancel']) && $form_state['values']['op'] === $form_state['values']['cancel']) {
    drupal_set_message('TFA setup canceled.');
    $form_state['redirect'] = 'user/' . $account->uid . '/security/tfa';
    return;
  }
  // Password validation.
  if (isset($form_state['values']['current_pass'])) {
    $form_state['storage']['pass_confirmed'] = TRUE;
    $form_state['rebuild'] = TRUE;
    return;
  }
  // Submitting mobile number step.
  elseif (!empty($form_state['values']['sms_number'])) {
    // Send code to number.
    $form_state['storage']['sms_number'] = $form_state['values']['sms_number'];
    $context = array('uid' => $account->uid, 'mobile_number' => $form_state['storage']['sms_number']);
    $setup_plugin = new TfaBasicSmsSetup($context, $form_state['storage']['sms_number']);
    $tfa_setup = new TfaSetup($setup_plugin, $context);
    $tfa_setup->begin();
    $errors = $tfa_setup->getErrorMessages();
    if (!empty($errors)) {
      foreach ($errors as $error) {
        form_set_error('number', $error);
      }
    }
    else {
      // No errors so store setup.
      $form_state['storage']['sms_setup'] = $tfa_setup;
    }
    $form_state['rebuild'] = TRUE;
    return;
  }
  // Disabling SMS delivery.
  if (isset($form_state['values']['sms_disable']) && $form_state['values']['op'] === $form_state['values']['sms_disable']) {
    tfa_basic_setup_save_data($account, array('sms' => FALSE));
    drupal_set_message(t('TFA SMS delivery disabled.'));
    $form_state['redirect'] = 'user/' . $account->uid . '/security/tfa';
    watchdog('tfa_basic', 'TFA SMS disabled for user @name UID !uid', array(
      '@name' => $account->name,
      '!uid' => $account->uid,
    ), WATCHDOG_INFO);
    return;
  }
  // Submitting a plugin form.
  elseif (!empty($form_state['storage']['method'])) {
    $method = $form_state['storage']['method'];

    // Support skipping optional steps.
    if (isset($form_state['values']['skip']) && $form_state['values']['op'] === $form_state['values']['skip']) {
      $skipped_method = array_pop($form_state['storage']['methods']);
      unset($form_state['storage'][$skipped_method]);
    }
    // Plugin form submit.
    elseif (!empty($form_state['storage'][$method])) {
      $setup_class = $form_state['storage'][$method];
      if (!$setup_class->submitForm($form, $form_state)) {
        drupal_set_message(t('There was an error during TFA setup. Your settings have not been saved.'), 'error');
        $form_state['redirect'] = 'user/' . $account->uid . '/security/tfa';
        return;
      }
    }

    // Save user TFA settings for relevant plugins.
    switch ($method) {
      case 'app_setup':
        drupal_set_message(t('Application code verified.'));
        tfa_basic_setup_save_data($account, array('method' => 'app'));
        break;

      case 'sms_setup':
        if (isset($form_state['storage']['sms_number']) && in_array('sms_setup', $form_state['storage']['methods'])) {
          // Update mobile number if different than stored.
          if ($form_state['storage']['sms_number'] !== tfa_basic_get_mobile_number($account)) {
            tfa_basic_set_mobile_number($account, $form_state['storage']['sms_number']);
          }
          tfa_basic_setup_save_data($account, array('sms' => TRUE));
        }
        break;
    }
    // Trigger multi-step plugins if this process began with application setup.
    if (in_array('app_setup', $form_state['storage']['methods']) && function_exists('_tfa_basic_' . $method . '_next_step')) {
      $function = '_tfa_basic_' . $method . '_next_step';
      $function($form_state, $form_state);
    }
    // Return if multi-step.
    if (isset($form_state['rebuild']) && $form_state['rebuild']) {
      return;
    }

    // Setup complete, return to overview page.
    drupal_set_message(t('TFA setup complete.'));
    $form_state['redirect'] = 'user/' . $account->uid . '/security/tfa';

    // Log and notify if app was set up.
    if (in_array('app_setup', $form_state['storage']['methods'])) {
      $params = array('account' => $account);
      drupal_mail('tfa_basic', 'tfa_basic_tfa_enabled', $account->mail, user_preferred_language($account), $params);
      watchdog('tfa_basic', 'TFA enabled for user @name UID !uid', array(
        '@name' => $account->name,
        '!uid' => $account->uid,
      ), WATCHDOG_INFO);
    }
  }
}

/**
 * Set step after application setup.
 */
function _tfa_basic_app_setup_next_step(&$form, &$form_state) {
  // Next step is SMS setup if enabled.
  $phone_field = variable_get('tfa_basic_phone_field', '');
  if ($phone_field !== '' && in_array('tfa_basic_sms', variable_get('tfa_fallback_plugins', array()))) {
    $form_state['rebuild'] = TRUE;
    $form_state['storage']['method'] = 'sms_setup';
    drupal_set_message(t('Provide a mobile phone number that can receive SMS codes in case you are without your phone or application and need to log in.'));
  }
  else {
    // Or whatever would be after SMS step.
    _tfa_basic_sms_setup_next_step($form, $form_state);
  }
}

/**
 * Set step after SMS setup.
 */
function _tfa_basic_sms_setup_next_step(&$form, &$form_state) {
  // Next step is trusted browsers if enabled.
  if (in_array('tfa_basic_trusted_device', variable_get('tfa_login_plugins', array()))) {
    $form_state['rebuild'] = TRUE;
    $form_state['storage']['method'] = 'trust_device';
    drupal_set_message(t('Mark this browser as trusted or skip to continue with TFA setup.'));
  }
  else {
    // Or whatever would be after trusted browser step.
    _tfa_basic_trust_device_next_step($form, $form_state);
  }
}

/**
 * Set step after trusted browsers.
 */
function _tfa_basic_trust_device_next_step(&$form, &$form_state) {
  // Final step is recovery codes if enabled.
  if (in_array('tfa_basic_recovery_code', variable_get('tfa_fallback_plugins', array()))) {
    $form_state['rebuild'] = TRUE;
    $form_state['storage']['method'] = 'recovery_codes';
    drupal_set_message(t('Save recovery codes or skip to complete TFA setup.'));
  }
}

/**
 * Output unused recovery codes.
 *
 * @param stdClass $account
 * @return string
 */
function tfa_basic_recovery_codes_list($account) {
  $recovery = new TfaBasicRecoveryCodeSetup(array('uid' => $account->uid));
  $codes = $recovery->getCodes();

  $output = theme('item_list', array('items' => $codes));
  $output .= l(t('Return to account TFA overview'), 'user/' . $account->uid . '/security/tfa');
  return $output;
}