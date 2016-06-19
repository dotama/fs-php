<?php

use \Firebase\JWT\JWT;

class JWTAndSessionAuthenticator implements RequestAuthenticator {
  private $secret;
  private $scope;
  private $algs;

  function JWTAndSessionAuthenticator($secret_key, $scope = 'bearer', $algs = array('HS256')) {
    $this->secret = $secret_key;
    $this->scope = $scope;
    $this->algs = $algs;

    # we don't want to leak that we are using PHP
    session_name("SESSIONID");

    # We don't want any php cache control headers
    session_cache_limiter("");
  }

  public function authenticate($url, $params, $headers) {
    if (isset($headers['authenticate'])) {
      $h = $headers['authenticate'];
      $s = mb_split(" ", $h, 2);
      if (mb_strtolower($s[0]) == $this->scope) {
        $userid = $this->authenticate_bearer($s[1]);
        if ($userid != null) {
          session_start();
          $_SESSION['userid'] = $userid;
          return $userid;
        }
      }
    }

    if (isset($_COOKIE['SESSIONID'])) {
      session_start();
      if (isset($_SESSION['userid'])) {
        return $_SESSION['userid'];
      }
    }
    return null;
  }

  // Decodes the bearer token as a JWT token and return the subject, if found.
  // The JWT token must be valid, alive and be signed by $this->secret.
  private function authenticate_bearer($bearer) {
    try {
        $decoded = JWT::decode($bearer, $this->secret, $this->algs);
        if (isset($decoded->sub)) {
          return $decoded->sub;
        }
    } catch(ExpiredException $e) {
      // that is fine, just ignore it.
    } catch(Exception $e) {
      error_log("Bearer token found, but failed to decode: $e");
    }
    return null;
  }
}
