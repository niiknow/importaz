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
        if ($this->getOrDefault("GET.user", null) == null) {
            echo 'OK';
            return;
        }

        $apiUser = $this->params["user"];
        // example generating api signature
        if ($this->getOrDefault("app.env", 'dev') === 'dev') {

            $users       = $this->getOrDefault("api_users", []);
            $algo        = $this->getOrDefault('security.algo', 'sha256');
            $users       = $this->getOrDefault("api_users", []);
            $passwerd    = $users[$apiUser];
            $time        = time();
            $validLength = 60 * 60; // valid for 1 hours
            $sig         = $this->generateSignature($time, $validLength, base64_encode($passwerd), $algo);
            echo $apiUser . ':' . $time . ',' . $validLength . ':' . $sig;
            return;
        }

        $this->f3->error('403', "this should only work in dev");
    }
}
