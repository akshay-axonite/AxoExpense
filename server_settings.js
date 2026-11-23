/**
 * Axonite Expense Tracking System - Server Settings Configuration
 * 
 * This file is loaded by index.html and desktop.html before the Vue application mounts.
 * It provides client-side feature flags and system configurations to the frontend.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */
(function () {
    window.EZBOOKKEEPING_SERVER_SETTINGS = window.EZBOOKKEEPING_SERVER_SETTINGS || {};
    const _ = window.EZBOOKKEEPING_SERVER_SETTINGS;

    // Feature Flags (1 = enabled, 0 = disabled)
    _['a'] = 1; // Enable internal username/password authentication
    _['o'] = 0; // Disable OAuth2/OIDC login
    _['r'] = 1; // Enable user registration
    _['f'] = 1; // Enable forgot password
    _['t'] = 1; // Enable API token generation
    _['v'] = 0; // Disable mandatory email verification
    _['c'] = 1; // Enable custom user icons
    _['p'] = 1; // Enable transaction picture / payment proof uploads
    _['s'] = 1; // Enable scheduled transactions
    _['e'] = 1; // Enable data export
    _['i'] = 1; // Enable data import
})();
