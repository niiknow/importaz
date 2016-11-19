<?php

namespace Controllers;

class Index extends BaseController
{
    public function index()
    {
        echo 'Hello World!\n';
    }

    public function hmac()
    {
        // example generating api signature
        if ($this->getOrDefault("app.env", 'dev') === 'dev') {
            $algo         = $this->getOrDefault('security.algo', 'sha256');
            $sharedSecret = $this->getOrDefault('security.secret');
            $time         = time();
            $validLength  = 60 * 60; // valid for 1 hours
            $sig          = $this->generateSignature($time, $validLength, base64_encode($sharedSecret), $algo);
            echo 'hmac:' . $time . ',' . $validLength . ':' . base64_encode($sig);
            return;
        }

        $this->f3->error('403', "this should only work in dev");
    }
}
