<?php

namespace Controllers;

class Index extends BaseController
{
    /**
     * health check
     */
    public function index()
    {
        echo 'OK';
    }

    /**
     * get hmac, only in dev
     */
    public function hmac()
    {
        $apiUser = $this->getOrDefault('GET.user', null);
        if ($apiUser == null) {
            echo 'OK';
            return;
        }

        // example generating api signature
        if ($this->getOrDefault('app.env', 'dev') === 'dev') {

            $algo        = $this->getOrDefault('security.algo', 'sha256');
            $users       = $this->getOrDefault('api_users', []);
            $passwerd    = $users[$apiUser];
            $time        = time();
            $validLength = 60 * 60; // valid for 1 hours
            $sig         = $this->generateSignature($passwerd, $time, $validLength, $apiUser, $algo);
            echo $sig;
            return;
        }

        $this->f3->error('403', 'this should only work in dev');
    }
}
