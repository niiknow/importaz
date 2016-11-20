<?php

namespace Controllers;

class BaseSecuredController extends BaseController
{
    /**
     * Authenticate
     */
    public function beforeRoute()
    {
        if (!isset($_SERVER['HTTP_X_AUTH'])) {
            $this->f3->error('403', 'X_AUTH header is required');
            return;
        }

        $hdr = $_SERVER['HTTP_X_AUTH'];

        // split to timestamp and signature
        $hdrs      = explode(':', $hdr);
        $method    = $hdrs[0];
        $tsField   = $hdrs[1]; // in seconds
        $signature = $hdrs[2]; // base64 encoded

        if ($method === 'hmac') {
            $algo         = $this->getOrDefault('security.algo', 'sha256');
            $sharedSecret = $this->getOrDefault('security.secret');

            // sharedSecret is encoded to base64 before validation
            if (isset($sharedSecret)
                && $this->isValidSignature($tsField, $signature, base64_encode($sharedSecret), $algo)) {
                return;
            }

            $this->f3->error('403', "X_AUTH header hmac validation failed");
            return;
        }

        $this->f3->error('403', "X_AUTH header method is invalid $method");
        return;
    }
}
