<?php

defined('\IPS\SUITE_UNIQUE_KEY') || exit;

class paysum_hook_Gateway extends _HOOK_CLASS_
{
    public static function gateways()
    {
        $gateways = parent::gateways();
        $gateways['paysum'] = 'IPS\paysum\extensions\nexus\Gateway\paysum';
        return $gateways;
    }
}
