<?php

/**
 * @file class for TFA Basic
 */

namespace Drupal\tfa_base;

use Drupal\tfa\TfaBasePlugin;
use Drupal\tfa\TfaValidationInterface;

/**
 * Default plugin implementation of the Entity Reference Selection plugin.  <<<<<<================== FIX ME START!!
 *
 * Also serves as a base class for specific types of Entity Reference
 * Selection plugins.
 *
 * @TfaBasicHelp(
 *   id = "default",
 *   label = @Translation("Default"),
 *   group = "default",
 *   weight = 0,
 *   deriver = "Drupal\tfa_base\TfaBasicHelp"
 * )
 */                                                                         //<<<<<<================== FIX ME END!!
class TfaBasicHelp extends TfaBasePlugin implements TfaValidationInterface {

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
