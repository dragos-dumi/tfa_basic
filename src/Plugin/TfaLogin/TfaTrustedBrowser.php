<?php

/**
 * @file classes for TFA basic plugin
 */

namespace Drupal\tfa_basic\Plugin\TfaLogin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tfa\Plugin\TfaBasePlugin;
use Drupal\tfa\Plugin\TfaLoginInterface;
use Drupal\Component\Utility\Crypt;

/**
 * @TfaLogin(
 *   id = "tfa_trusted_browser",
 *   label = @Translation("TFA Trusted Browser"),
 *   description = @Translation("TFA Trusted Browser Plugin")
 * )
 */
class TfaTrustedBrowser extends TfaBasePlugin implements TfaLoginInterface {

  /**
   * @var bool
   */
  protected $trustBrowser;

  /**
   * @var string
   */
  protected $cookieName;

  /**
   * @var string
   */
  protected $domain;

  /**
   * @var string
   */
  protected $expiration;

  protected $database;

  public function __construct(array $context) {
    parent::__construct($context);
    $config = \Drupal::config('tfa_basic.settings');
    $this->cookieName = $config->get('cookie_name');
    $this->domain = $config->get('cookie_domain');
    // Expiration defaults to 30 days.
    $this->expiration = $config->get('trust_cookie_expiration', 3600 * 24 * 30);
    $this->database = \Drupal::database();
  }

  /**
   * @return bool
   */
  public function loginAllowed() {
    if (isset($_COOKIE[$this->cookieName]) && ($did = $this->trustedBrowser($_COOKIE[$this->cookieName])) !== FALSE) {
      $this->setUsed($did);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @copydoc TfaValidationPluginInterface::getForm()
   */
  public function getForm(array $form, FormStateInterface $form_state) {
    $form['trust_browser'] = array(
      '#type' => 'checkbox',
      '#title' => t('Remember this browser?'),
      '#description' => t('Not recommended if you are on a public or shared computer.'),
    );
    return $form;
  }

  /**
   * @copydoc TfaValidationPluginInterface::validateForm()
   */
  public function validateForm(array $form, FormStateInterface $form_state) {
  }

  /**
   * @copydoc TfaBasePlugin::submitForm()
   */
  public function submitForm(array $form, FormStateInterface $form_state) {
    $trust_browser = $form_state->getValue('trust_browser');
    if (!empty($trust_browser)) {
      $this->trustBrowser = TRUE;
    }
    else {
      $this->trustBrowser = FALSE;
    }
  }

  /**
   *
   */
  public function finalize() {
    if ($this->trustBrowser) {
      $name = $this->getAgent();
      $this->setTrusted($this->generateBrowserId(), $name);
    }
  }

  /**
   * Generate a random value to identify the browser.
   *
   * @return string
   */
  protected function generateBrowserId() {
    $id = base64_encode(Crypt::randomBytes(32));
    return strtr($id, array('+' => '-', '/' => '_', '=' => ''));
  }

  /**
   * Store browser value and issue cookie for user.
   *
   * @param string $value
   * @param string $name
   */
  protected function setTrusted($value, $name = '') {
    // Store id for account.
    $record = array(
      'uid' => $this->context['uid'],
      'value' => $value,
      'created' => REQUEST_TIME,
      'ip' => \Drupal::request()->getClientIp(),
      'name' => $name,
    );

    $this->database->insert('tfa_trusted_browser', $record)
      ->fields($record)
      ->execute();
    // Issue cookie with ID.
    $cookie_secure = ini_get('session.cookie_secure');
    $expiration = REQUEST_TIME + $this->expiration;
    setcookie($this->cookieName, $value, $expiration, '/', $this->domain, (empty($cookie_secure) ? FALSE : TRUE), TRUE);
    $name = empty($name) ? $this->getAgent() : $name;
    // TODO - use services defined in module instead this procedural way.
    \Drupal::logger('tfa_basic')->info('Set trusted browser for user UID !uid, browser @name', array('@name' => $name, '!uid' => $this->context['uid']));
  }

  /**
   * Updated browser last used time.
   *
   * @param int $did
   *   Internal browser ID to update.
   */
  protected function setUsed($did) {
    $record = array(
      'last_used' => REQUEST_TIME,
    );
    $this->database->update('tfa_trusted_browser')
      ->fields($record)
      ->condition('did', $did)
      ->execute();
  }

  /**
   * Check if browser value matches user's saved browser.
   *
   * @param string $value
   * @return int|FALSE
   *   Browser ID if trusted or else FALSE.
   */
  protected function trustedBrowser($value) {
    // Check if $id has been saved for this user.
    $query = $this->database->select('tfa_trusted_browser', 'tb', array('fetch' => \PDO::FETCH_ASSOC))
      ->fields('tb')
      ->condition('value', $value)
      ->condition('uid', $this->context['uid']);
    $result = $query->execute()->fetch();

    if (!empty($result['did'])) {
      return $result['did'];
    }
    return FALSE;
  }

  /**
   * Delete users trusted browsers.
   *
   * @param int $did
   *   Optional trusted browser id to delete.
   *
   * @return int
   */
  protected function deleteTrusted($did = NULL) {
    $query = $this->database->delete('tfa_trusted_browser')
      ->condition('uid', $this->context['uid']);
    if (is_int($did)) {
      $query->condition('did', $did);
    }

    return $query->execute();
  }

  /**
   * Get simplified browser name from user agent.
   *
   * @param string $name Default name.
   *
   * @return string
   */
  protected function getAgent($name = '') {
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
      // Match popular user agents.
      $agent = $_SERVER['HTTP_USER_AGENT'];
      if (preg_match("/like\sGecko\)\sChrome\//", $agent)) {
        $name = 'Chrome';
      }
      elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'Firefox') !== FALSE) {
        $name = 'Firefox';
      }
      elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== FALSE) {
        $name = 'Internet Explorer';
      }
      elseif (strpos($_SERVER['HTTP_USER_AGENT'], 'Safari') !== FALSE) {
        $name = 'Safari';
      }
      else {
        // Otherwise filter agent and truncate to column size.
        $name = substr($agent, 0, 255);
      }
    }
    return $name;
  }

}
