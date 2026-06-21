<?php
$route['default_controller'] = 'home';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// Phase 2: Auth Routes
$route['login'] = 'auth/login';
$route['register'] = 'auth/register';
$route['logout'] = 'auth/logout';

// Phase 5: Wallet Routes
$route['wallet/simulate_payment/(:any)'] = 'wallet/simulate_payment/$1';

// Phase 6: Rentals Routes
$route['rentals/checkout'] = 'rentals/checkout';
$route['rentals/claim/(:num)'] = 'rentals/claim/$1';

// Phase 7: Admin Portal (cloaked)
$route['control-panel'] = 'Admin_auth/login';
