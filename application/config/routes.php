<?php
$route['default_controller'] = 'home';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// Phase 2: Auth Routes
$route['login'] = 'auth/login';
$route['register'] = 'auth/register';
$route['logout'] = 'auth/logout';
$route['auth/change-password'] = 'auth/change_password';

// Phase 5: Wallet Routes
$route['wallet/simulate_payment/(:any)'] = 'wallet/simulate_payment/$1';

// C7 (plan 42): dev/UAT-only WD simulator — production-inert (gate in controller).
$route['wallet/simulate_wd_approve/(:any)'] = 'wallet/simulate_wd_approve/$1';

// Phase 6: Rentals Routes
$route['rentals/checkout'] = 'rentals/checkout';
$route['rentals/claim/(:num)'] = 'rentals/claim/$1';

// Profile
$route['profile'] = 'profile/index';
$route['profile/update'] = 'profile/update';
$route['profile/avatar_delete'] = 'profile/avatar_delete';
$route['profile/change-password'] = 'profile/change_password';

// Phase 7: Admin Portal (cloaked)
$route['control-panel'] = 'Admin_auth/login';
$route['admin/logout'] = 'admin_auth/logout';

// M1 (plan/56): Admin financial rules (dynamic WD/deposit config)
$route['admin/financial-settings'] = 'admin/financial_settings';

// Phase 8: Bank Binding
$route['wallet/bind_bank'] = 'wallet/bind_bank';

// Phase 8.2: Team & Affiliates
$route['team'] = 'team/index';

// Phase 8B: Help / FAQ
$route['help'] = 'help/index';

// Phase 9: Notifications (AJAX)
$route['user/read_notifications'] = 'user/read_notifications';
