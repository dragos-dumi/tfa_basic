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
 * TFA Basic account setup overview page.
 */
class BasicOverview extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tfa_basic_base_overview';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL) {

    $output['info'] = array(
      '#type' => 'markup',
      '#markup' => '<p>' . t('Two-factor authentication (TFA) provides additional security for your account. With TFA enabled, you log in to the site with a verification code in addition to your username and password.') . '</p>',
    );
    //$form_state['storage']['account'] = $user;
    $user_tfa = tfa_basic_get_tfa_data($user->id());
    $enabled = isset($user_tfa['status']) && $user_tfa['status'] ? TRUE : FALSE;

    if (!empty($user_tfa)) {
      if ($enabled) {
        $status_text = t('Status: <strong>TFA enabled</strong>, set !time. <a href="!url">Disable TFA</a>', array(
          '!time' => format_date($user_tfa['saved']),
          '!url' => URL::fromRoute('tfa_basic.tfa.disable', ['user' => $user->id()])->toString()
        ));
      }
      else {
        $status_text = t('Status: <strong>TFA disabled</strong>, set !time.', array('!time' => format_date($user_tfa['saved'])));
      }
      $output['status'] = array(
        '#type' => 'markup',
        '#markup' => '<p>' . $status_text . '</p>',
      );
    }

    if ($enabled) { //$todo (!$enabled)
      $validate_plugin = \Drupal::config('node.settings')->get('tfa_validate_plugin'); //@todo
      $output['setup'] = $this->tfa_basic_plugin_setup_form_overview($validate_plugin, $user, $user_tfa);
    }
    else {
      // TOTP setup.
      $output['app'] = $this->tfa_basic_plugin_setup_form_overview('tfa_basic_totp', $user, $user_tfa);
      // SMS setup.
      $output['sms'] = $this->tfa_basic_plugin_setup_form_overview('tfa_basic_sms', $user, $user_tfa);
      // Trusted browsers.
      $output['trust'] = $this->tfa_basic_plugin_setup_form_overview('tfa_basic_trusted_browser', $user, $user_tfa);
      // Recovery codes.
      $output['recovery'] = $this->tfa_basic_plugin_setup_form_overview('tfa_basic_recovery_code', $user, $user_tfa);
    }


    return $output;
  }

  /**
   * Get TFA basic setup action links for use on overview page.
   *
   * @param string $plugin
   * @param object $account
   * @param array $user_tfa
   *
   * @return array
   *   Render array
   */
  public function tfa_basic_plugin_setup_form_overview($plugin, $account, array $user_tfa) {
    // No output if the plugin isn't enabled.
    /*if ($plugin !== variable_get('tfa_validate_plugin', '') &&
      !in_array($plugin, variable_get('tfa_fallback_plugins', array())) &&
      !in_array($plugin, variable_get('tfa_login_plugins', array()))) {
      return array();
    }*/
    $enabled = isset($user_tfa['status']) && $user_tfa['status'] ? TRUE : FALSE;
    $output = array();
    switch ($plugin) {
      case 'tfa_basic_totp';
        $output = array(
          'heading' => array(
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => t('TFA application'),
          ),
          'description' => array(
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => t('Generate verification codes from a mobile or desktop application.'),
          ),
          'link' => array(
            '#theme' => 'links',
            '#links' => array(
              'admin' => array(
                'title' => !$enabled ? t('Set up application') : t('Reset application'),
                'url' => Url::fromUri('base:user/' . $account->id() . '/security/tfa/app-setup'),
              ),
            ),
          ),
        );
        break;

      case 'tfa_basic_sms':
      case 'tfa_basic_trusted_browser':
      case 'tfa_basic_recovery_code':

        break;

    }
    return $output;
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
