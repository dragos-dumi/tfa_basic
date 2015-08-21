<?php

/**
 * @file class for TFA Basic
 */

namespace Drupal\tfa_basic\Plugin\TfaValidation;

use Drupal\tfa\Plugin\TfaBasePlugin;
use Drupal\tfa\Plugin\TfaValidationInterface;

/**
 * @TfaValidation(
 *   id = "tfa_basic_help_validation",
 *   label = @Translation("TFA Basic Help"),
 *   description = @Translation("TFA Basic Help Plugin")
 * )
 */
class TfaBasicHelpValidation extends TfaBasePlugin implements TfaValidationInterface {

  /**
   * @copydoc TfaBasePlugin::getForm()
   */
  public function getForm(array $form, array &$form_state) {
    $default = t('Contact support to reset your access');
    $content = variable_get('tfa_basic_help_text', $default);
    $form['help'] = array(
      '#type' => 'markup',
      '#markup' => check_plain($content),
    );
    // Disallow login plugins from applying to this step.
    $form['#tfa_no_login'] = TRUE;
    return $form;
  }

  /**
   * @copydoc TfaValidationPluginInterface::validateForm()
   */
  public function validateForm(array $form, array &$form_state) {
    // Unused.
  }

}
