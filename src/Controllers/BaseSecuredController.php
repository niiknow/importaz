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
            $this->f3->error('403', 'X-AUTH header is required');
            return;
        }

        $hdr = $_SERVER['HTTP_X_AUTH'];

        // split to timestamp and signature
        $hdrs      = explode(':', $hdr);
        $apiUser   = $hdrs[2];

        $users = $this->getOrDefault('api_users', []);
        if (isset($users[$apiUser])) {
            $algo     = $this->getOrDefault('security.algo', 'sha256');
            $passwerd = $users[$apiUser];

            // sharedSecret is encoded to base64 before validation
            if (isset($passwerd)
                && $this->isValidSignature($passwerd, $hdr, $algo)) {
                return;
            }

            $this->f3->error('403', 'X-AUTH header hmac validation failed');
            return;
        }

        $this->f3->error('403', 'X-AUTH user is invalid');
        return;
    }
}
