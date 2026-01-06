<?php

return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'redirect_uri' => env('META_REDIRECT_URI'),
    'graph_version' => env('META_GRAPH_VERSION', 'v20.0'),
    'oauth_scopes' => env('META_OAUTH_SCOPES', 'public_profile,email,ads_management,ads_read,business_management,pages_show_list,instagram_basic'),
    'font_path' => env('META_FONT_PATH', resource_path('fonts/meta-ads-bold.ttf')),
];
