<?php

namespace Drupal\verify_dam;

/**
 * Thrown when the Verify API call fails.
 */
class VerifyApiException extends \RuntimeException {

  /**
   * Whether the failure was caused by an invalid or missing API key.
   */
  public function isAuthError(): bool {
    return in_array($this->getCode(), [401, 403], TRUE);
  }

}
