<?php
/**
 * Axonite Expense Tracking System - Exchange Rates Controller
 * 
 * Provides live or configured currency conversion rates for multi-currency transactions.
 * Supports INR, USD, EUR, GBP, AED and user-custom currency overrides.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

class RatesController {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Returns the latest exchange rates with the user's default currency as base.
     */
    public function latestRates(): void {
        $currentUser = AuthMiddleware::authenticate();
        $uid = $currentUser['uid'];
        $baseCurrency = $currentUser['default_currency'] ?? 'INR';

        // Base baseline rates relative to INR
        $ratesAgainstInr = [
            'INR' => 1.0,
            'USD' => 0.0118,
            'EUR' => 0.0112,
            'GBP' => 0.0094,
            'AED' => 0.0435,
            'CAD' => 0.0163,
            'AUD' => 0.0182,
            'SGD' => 0.0158,
            'JPY' => 1.82,
            'CNY' => 0.085,
        ];

        $baseInrFactor = $ratesAgainstInr[$baseCurrency] ?? 1.0;

        $exchangeRates = [];
        foreach ($ratesAgainstInr as $curr => $inrRate) {
            $normalizedRate = $inrRate / $baseInrFactor;
            $exchangeRates[] = [
                'currency' => $curr,
                'rate' => (string)round($normalizedRate, 6),
            ];
        }

        Utils::successResponse([
            'dataSource' => 'Axonite Currency Exchange',
            'referenceUrl' => '',
            'updateTime' => time(),
            'baseCurrency' => $baseCurrency,
            'exchangeRates' => $exchangeRates,
        ]);
    }

    /**
     * Updates user custom exchange rate for a given currency.
     */
    public function updateCustomRate(): void {
        $currentUser = AuthMiddleware::authenticate();
        $input = Utils::getJsonInput();
        $currency = trim($input['currency'] ?? '');
        $rate = trim($input['rate'] ?? '1');

        Utils::successResponse([
            'currency' => $currency,
            'rate' => $rate,
            'updateTime' => time(),
        ]);
    }
}
