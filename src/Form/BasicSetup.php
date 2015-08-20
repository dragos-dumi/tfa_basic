<?php

/**
 * @file
 * Contains \Drupal\tfa_basic\Form\BasicOverview.
 */

namespace Drupal\tfa_basic\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Url;

/**
 * TFA setup form router.
 */
class BasicSetup extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tfa_basic_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL) {

    $account = \Drupal::currentUser();

    $form['account'] = array(
      '#type' => 'value',
      '#value' => $user,
    );
    $tfa_data = tfa_basic_get_tfa_data($user->id());
    $enabled = isset($tfa_data['status']) && $tfa_data['status'] ? TRUE : FALSE;

    $storage = $form_state->getStorage();
    // Always require a password on the first time through.
    if (empty($storage)) {
      // Allow administrators to change TFA settings for another account.
      if ($account->id() == $user->id()) { //@todo && user_access('administer users')
        $current_pass_description = t('Enter your current password to alter TFA settings for account %name.', array('%name' => $user->getUsername()));
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
      // If TFA is not enabled setup each plugin by using enabled plugins as form
      // steps.
      if (!$enabled && empty($form_state['storage']['steps'])) {
        $form_state['storage']['full_setup'] = TRUE;
        $steps = _tfa_basic_full_setup_steps($method);
        $form_state['storage']['steps_left'] = $steps;
        $form_state['storage']['steps_skipped'] = array();
      }

      // Override provided method if operating under multi-step.
      if (isset($form_state['storage']['step_method'])) {
        $method = $form_state['storage']['step_method'];
      }
      // Record methods progressed.
      $form_state['storage']['steps'][] = $method;
      $context = array('uid' => $account->uid);
      switch ($method) {
        case 'tfa_basic_totp':
          drupal_set_title(t('TFA setup - Application'));
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

        case 'tfa_basic_trusted_browser':
          drupal_set_title(t('TFA setup - Trusted browsers'));
          $setup_plugin = new TfaTrustedBrowserSetup($context);
          $tfa_setup = new TfaSetup($setup_plugin, $context);
          $form = $tfa_setup->getForm($form, $form_state);
          $form_state['storage'][$method] = $tfa_setup;
          break;

        case 'tfa_basic_recovery_code':
          drupal_set_title(t('TFA setup - Recovery codes'));
          $setup_plugin = new TfaBasicRecoveryCodeSetup($context);
          $tfa_setup = new TfaSetup($setup_plugin, $context);
          $form = $tfa_setup->getForm($form, $form_state);
          $form_state['storage'][$method] = $tfa_setup;
          break;

        case 'tfa_basic_sms':
          drupal_set_title(t('TFA setup - SMS'));
          // SMS itself has multiple steps. Begin with phone number entry.
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
            if (isset($form_state['storage']['full_setup'])) {
              drupal_set_message(t("If the code does not arrive or you entered the wrong number skip this step to continue without SMS delivery. You can enable it after completing the rest of TFA setup."));
            }
            else {
              $form['sms_code']['#description'] .= ' ' . l(t('If the code does not arrive or you entered the wrong number click here to start over.'), 'user/' . $account->uid . '/security/tfa/sms-setup');
            }

            $form_state['storage'][$method] = $tfa_setup;
          }
          break;

        // List previously saved recovery codes. Note, this is not a plugin.
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

        default:
          break;
      }
      // Provide skip button under full setup.
      if (isset($form_state['storage']['full_setup']) && count($form_state['storage']['steps']) > 1) {
        $count = count($form_state['storage']['steps_left']);
        $form['actions']['skip'] = array(
          '#type' => 'submit',
          '#value' => $count > 0 ? t('Skip') : t('Skip and finish'),
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
      // Record the method in progress regardless of whether in full setup.
      $form_state['storage']['step_method'] = $method;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {


  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
