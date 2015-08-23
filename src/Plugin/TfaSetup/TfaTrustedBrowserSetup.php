<?php

namespace Drupal\tfa_basic\Plugin\TfaSetup;

use Drupal\tfa\Plugin\TfaSetupInterface;
use Drupal\tfa_basic\Plugin\TfaValidation\TfaTrustedBrowser;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * @TfaSetup(
 *   id = "tfa_basic_trusted_browser_setup",
 *   label = @Translation("TFA Trusted Browser Setup"),
 *   description = @Translation("TFA Trusted Browser Setup Plugin")
 * )
 */
class TfaTrustedBrowserSetup extends TfaTrustedBrowser implements TfaSetupInterface {

  public function __construct(array $context) {
    parent::__construct($context);
  }

  /**
   * @copydoc TfaSetupPluginInterface::getSetupForm()
   */
  public function getSetupForm(array $form, FormStateInterface &$form_state) {
    $existing = $this->getTrustedBrowsers();
    $time = variable_get('tfa_basic_trust_cookie_expiration', 3600 * 24 * 30) / (3600 * 24);
    $form['info'] = array(
      '#type' => 'markup',
      '#markup' => '<p>' . t("Trusted browsers are a method for simplifying login by avoiding verification code entry for a set amount of time, !time days from marking a browser as trusted. After !time days, to log in you'll need to enter a verification code with your username and password during which you can again mark the browser as trusted.", array('!time' => $time)) . '</p>',
    );
    // Present option to trust this browser if its not currently trusted.
    if (isset($_COOKIE[$this->cookieName]) && ($browser_id = $this->trustedBrowser($_COOKIE[$this->cookieName])) !== FALSE) {
      $current_trusted = $browser_id;
    }
    else {
      $current_trusted = FALSE;
      $form['trust'] = array(
        '#type' => 'checkbox',
        '#title' => t('Trust this browser?'),
        '#default_value' => empty($existing) ? 1 : 0,
      );
      // Optional field to name this browser.
      $form['name'] = array(
        '#type' => 'textfield',
        '#title' => t('Name this browser'),
        '#maxlength' => 255,
        '#description' => t('Optionally, name the browser on your browser (e.g. "home firefox" or "office desktop windows"). Your current browser user agent is %browser', array('%browser' => $_SERVER['HTTP_USER_AGENT'])),
        '#default_value' => $this->getAgent(),
        '#states' => array(
          'visible' => array(
            ':input[name="trust"]' => array('checked' => TRUE),
          ),
        ),
      );
    }

    if (!empty($existing)) {
      $form['existing'] = array(
        '#type' => 'fieldset',
        '#title' => t('Existing browsers'),
        '#description' => t('Leave checked to keep these browsers in your trusted log in list.'),
        '#tree' => TRUE,
      );
      foreach ($existing as $browser) {
        $vars = array(
          '!set' => format_date($browser['created']),
          '!time' => format_date($browser['last_used']),
        );
        if ($current_trusted == $browser['id']) {
          $name = '<strong>' . t('@name (current browser)', array('@name' => $browser['name'])) . '</strong>';
        }
        else {
          $name = check_plain($browser['name']);
        }
        if (empty($browser['last_used'])) {
          $message = t('Marked trusted !set', $vars);
        }
        else {
          $message = t('Marked trusted !set, last used for log in !time', $vars);
        }
        $form['existing']['trusted_browser_' . $browser['id']] = array(
          '#type' => 'checkbox',
          '#title' => $name,
          '#description' => $message,
          '#default_value' => 1,
        );
      }
    }
    $form['actions']['save'] = array(
      '#type' => 'submit',
      '#value' => t('Save'),
    );

    return $form;
  }

  /**
   * @copydoc TfaSetupPluginInterface::validateSetupForm()
   */
  public function validateSetupForm(array $form, FormStateInterface &$form_state) {
    return TRUE; // Do nothing, no validation required.
  }

  /**
   * @copydoc TfaSetupPluginInterface::submitSetupForm()
   */
  public function submitSetupForm(array $form, FormStateInterface &$form_state) {
    if (isset($form_state['values']['existing'])) {
      $count = 0;
      foreach ($form_state['values']['existing'] as $element => $value) {
        $id = str_replace('trusted_browser_', '', $element);
        if (!$value) {
          $this->deleteTrusted($id);
          $count++;
        }
      }
      if ($count) {
        watchdog('tfa_basic', 'Removed !num TFA trusted browsers during trusted browser setup', array('!num' => $count), WATCHDOG_INFO);
      }
    }

    if (!empty($form_state['values']['trust']) && $form_state['values']['trust']) {
      $name = '';
      if (!empty($form_state['values']['name'])) {
        $name = $form_state['values']['name'];
      }
      elseif (isset($_SERVER['HTTP_USER_AGENT'])) {
        $name = $this->getAgent();
      }

      $this->setTrusted($this->generateBrowserId(), $name);
    }
    return TRUE;
  }

  /**
   * Get list of trusted browsers.
   *
   * @return array
   */
  public function getTrustedBrowsers() {
    $browsers = array();
    $result = db_query("SELECT did, name, ip, created, last_used FROM {tfa_trusted_browser} WHERE uid = :uid", array(':uid' => $this->context['uid']));
    if ($result) {
      foreach ($result as $row) {
        $browsers[] = array(
          'id' => $row->did,
          'name' => $row->name,
          'created' => $row->created,
          'ip' => $row->ip,
          'last_used' => $row->last_used,
        );
      }
    }
    return $browsers;
  }

  /**
   * Delete a trusted browser by its ID.
   *
   * @param int $id
   * @return int
   */
  public function deleteTrustedId($id) {
    return $this->deleteTrusted($id);
  }

  /**
   * Delete all trusted browsers.
   *
   * @return int
   */
  public function deleteTrustedBrowsers() {
    return $this->deleteTrusted();
  }
}
