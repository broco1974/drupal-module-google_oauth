<?php
/**
 * Our first Drupal 8 controller.
 */
namespace Drupal\google_oauth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\user\Entity\User;
use Google_Client;
use Google_Service_Oauth2;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GoogleOAuthController extends ControllerBase {
  private $client;

  private $settings;

  public function __construct() {
    $private_path = PrivateStream::basePath();
    $config_file = $private_path . '/google-oauth-secret.json';

    $this->settings = \Drupal::config('google_oauth.settings');

    if (!is_readable($config_file)) {
      // Nag ?
      return;
    }

    $this->client = new Google_Client();
    $this->client->setAuthConfigFile($config_file);
    $this->client->setScopes(array('email'));
    $this->client->setState('offline');

    // Set the redirect URL which is used when redirecting and verifying
    // the one-time oauth code.
    $uri = \Drupal::url('google_oauth.authenticate', array(), array('absolute' => TRUE));

    $this->client->setRedirectUri($uri);
  }

  public function login() {
    if (!$this->client) {
      return;
    }

    return new TrustedRedirectResponse($this->client->createAuthUrl(), 301);
  }

  /**
   * Authenticate, save user details, return access token
   */
  public function authenticate() {
    $code = filter_input(INPUT_GET, 'code');

    if (empty($code) || !$this->client) {
      return $this->authenticateFailedAction();
    }

    try {
      $this->client->authenticate($code);
      $plus = new Google_Service_Oauth2($this->client);
      $userinfo = $plus->userinfo->get();
    }
    catch (\Exception $e) {
      return $this->authenticateFailedAction();
    }

    $user_email = $userinfo['email'];

    $user = user_load_by_mail($user_email);

    if (!$user) {
      $allowed_email_regex = $this->settings->get('allowed_email_regex');

      if ($allowed_email_regex) {
        if (!preg_match($allowed_email_regex, $user_email)) {
          return $this->authenticateFailedAction();
        }
      }

      $user_name = $userinfo['name'];
      $user_picture = $userinfo['picture'];

      try {
        $user = User::create([
          'name' => $user_name,
          'mail' => $user_email,
          'status' => 1,
          'picture' => $user_picture,
        ]);

        // hook_google_oauth_create_user_alter($user, $userinfo);
        \Drupal::moduleHandler()->alter('google_oauth_create_user', $user, $userinfo);
        $user->save();
      }
      catch (\Exception $e) {
        return $this->authenticateFailedAction();
      }
    }

    user_login_finalize($user);

    return $this->authenticateFailedAction();
  }

  /**
   * Authenticate failed action
   */
  protected function authenticateFailedAction($path = '<front>') {
    return $this->redirect($path);
  }

}
