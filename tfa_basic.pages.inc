<?php

function tfa_basic_disable_form($form, &$form_state, $account) {
  global $user;

  $form_state['storage']['account'] = $account;

  if ($account->uid != $user->uid && user_access('administer users')) {
    $preamble_desc = t('Are you sure you want to disable TFA on account %name?', array('%name' => $account->name));
    $notice_desc = t('TFA settings and data will be lost. %name can re-enable TFA again from their profile.', array('%name' => $account->name));
    if (tfa_basic_tfa_required($account)) {
      drupal_set_message(t("This account is required to have TFA enabled per the 'require TFA' permission on one of their roles. Disabling TFA will remove their ability to log back into the site. If you continue, consider also removing the role so they can authenticate and setup TFA again."), 'warning');
    }
  }
  else {
    $preamble_desc = t('Are you sure you want to disable your two-factor authentication setup?');
    $notice_desc = t("Your settings and data will be lost. You can re-enable two-factor authentication again from your profile.");
    if (tfa_basic_tfa_required($account)) {
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
  // Delete trusted browsers.
  $trusted = new TfaTrustedBrowserSetup(array('uid' => $account->uid));
  $trusted->deleteTrustedBrowsers();

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
 * Steps eligble for TFA Basic setup.
 */
function _tfa_basic_full_setup_steps() {
  $steps = array();
  $plugins = array(
    'tfa_basic_totp',
    'tfa_basic_sms',
    'tfa_basic_trusted_browser',
    'tfa_basic_recovery_code',
  );
  foreach ($plugins as $plugin) {
    if ($plugin === variable_get('tfa_validate_plugin', '') ||
      in_array($plugin, variable_get('tfa_fallback_plugins', array())) ||
      in_array($plugin, variable_get('tfa_login_plugins', array()))) {
      $steps[] = $plugin;
    }
  }
  return $steps;
}

/**
 * Set form rebuild, next step, and message if any plugin steps left.
 */
function _tfa_basic_set_next_step(&$form_state, $this_step, $skipped_step = FALSE) {
  // Remove this step from steps left.
  $form_state['storage']['steps_left'] = array_diff($form_state['storage']['steps_left'], array($this_step));
  if (!empty($form_state['storage']['steps_left'])) {
    // Contextual reporting.
    $output = FALSE;
    switch ($this_step) {
      case 'tfa_basic_totp':
        $output = $skipped_step ? t('Application codes not enabled.') : t('Application code verified.');
        break;

      case 'tfa_basic_sms':
        $output = $skipped_step ? t('SMS code delivery not enabled.') : t('SMS code verified.');
        break;

      case 'tfa_basic_trusted_browser':
        // Handle whether the checkbox was unchecked.
        if ($skipped_step || empty($form_state['values']['trust'])) {
          $output = t('Browser not saved.');
        }
        else {
          $output = t('Browser saved.');
        }
        break;

      case 'tfa_basic_recovery_code':
        $output = $skipped_step ? t('Recovery codes not saved.') : t('Saved recovery codes.');
        break;
    }
    $count = count($form_state['storage']['steps_left']);
    $output .= ' ' . format_plural($count, 'One setup step remaining.', '@count TFA setup steps remain.', array('@count' => $count));
    if ($output) {
      drupal_set_message($output);
    }

    // Set next step and mark form for rebuild.
    $next_step = array_shift($form_state['storage']['steps_left']);
    $form_state['storage']['step_method'] = $next_step;
    $form_state['rebuild'] = TRUE;
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
