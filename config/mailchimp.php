<?php

use App\Models\Instance\SystemSetting;

$apikey = SystemSetting::where('key','mc_key')->get(['value'])->first();

return [
    'apikey' => env($apikey),
];
