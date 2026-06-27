<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://127.0.0.1:3000'],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];
