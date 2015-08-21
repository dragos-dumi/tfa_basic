<?php

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
