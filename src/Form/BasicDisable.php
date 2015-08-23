<?php

/**
 * @file
 * Contains \Drupal\tfa_basic\Form\BasicOverview.
 */

namespace Drupal\tfa_basic\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\tfa\TfaSetup;
use Drupal\tfa_basic\Plugin\TfaValidation\TfaTotp;
use Drupal\user\Entity\User;
use Drupal\tfa_basic\Plugin\TfaSetup\TfaBasicRecoveryCodeSetup;
use Drupal\tfa_basic\Plugin\TfaSetup\TfaTrustedBrowserSetup;

/**
 * TFA setup form router.
 */
class BasicDisable extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tfa_basic_disable';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, User $user = NULL) {
    $account = User::load(\Drupal::currentUser()->id());

    $storage = $form_state->getStorage();
    $storage['account'] = $user;

    if ($account->id() != $user->id() && $account->hasPermission('administer users')) {
      $preamble_desc = t('Are you sure you want to disable TFA on account %name?', array('%name' => $user->getUsername()));
      $notice_desc = t('TFA settings and data will be lost. %name can re-enable TFA again from their profile.', array('%name' => $user->getUsername()));
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
      '#value' => $user->getEmail(),
    );
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Disable'),
    );
    $form['actions']['cancel'] = array(
      '#type' => 'submit',
      '#value' => t('Cancel'),
      '#limit_validation_errors' => array(),
    );

    $form_state->setStorage($storage);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $user = User::load(\Drupal::currentUser()->id());
    $storage = $form_state->getStorage();
    /** @var User $account */
    $account = $storage['account'];
    // Allow administrators to disable TFA for another account.
    if ($account->id() != $user->id() && $user->hasPermission('administer users')) {
      $account = $user;
    }
    // Check password.
    $current_pass = \Drupal::service('password')->check(trim($form_state->getValue('current_pass')), $account->getPassword());
    if (!$current_pass) {
      $form_state->setErrorByName('current_pass', t("Incorrect password."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $storage = $form_state->getStorage();
    $values = $form_state->getValues();
    /** @var User $account */
    $account = $storage['account'];
    if ($values['op'] === $values['cancel']) {
      drupal_set_message(t('TFA disable canceled.'));
      $form_state->setRedirect('tfa_basic.tfa', ['user' => $account->id()]);
      return;
    }

    tfa_basic_setup_save_data($account, array('status' => FALSE));
    // Delete TOTP code.
    $totp = new TfaTotp(array('uid' => $account->id()));
    $totp->deleteSeed();
    // Delete recovery codes.
    $recovery = new TfaBasicRecoveryCodeSetup(array('uid' => $account->id()));
    $recovery->deleteCodes();
    // Delete trusted browsers.
    $trusted = new TfaTrustedBrowserSetup(array('uid' => $account->id()));
    $trusted->deleteTrustedBrowsers();

    // @todo
//    watchdog('tfa_basic', 'TFA disabled for user @name UID !uid', array(
//      '@name' => $account->getUsername(),
//      '!uid' => $account->id(),
//    ), WATCHDOG_NOTICE);

    // @todo
    // E-mail account to inform user that it has been disabled.
//    $params = array('account' => $account);
//    drupal_mail('tfa_basic', 'tfa_basic_disabled_configuration', $account->getEmail(), user_preferred_language($account), $params);

    drupal_set_message(t('TFA has been disabled.'));
    $form_state->setRedirect('tfa_basic.tfa', ['user' => $account->id()]);
  }

}
