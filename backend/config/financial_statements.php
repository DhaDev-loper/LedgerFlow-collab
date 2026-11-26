<?php
declare(strict_types=1);

require_once __DIR__ . '/report_helper.php';

function statementMoney(float|int|string $amount): float
{
    return round((float) $amount, 2);
}

function statementAccountMatches(string $name, array $patterns): bool
{
    if ($patterns === []) {
        return true;
    }
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $name) === 1) {
            return true;
        }
    }
    return false;
}

function statementAccountTotal(array $accounts, array $patterns, ?string $type = null): float
{
    $total = 0.0;
    foreach ($accounts as $account) {
        if (($type === null || $account['type_name'] === $type)
            && statementAccountMatches((string) $account['name'], $patterns)) {
            $total += (float) $account['balance'];
        }
    }
    return statementMoney($total);
}

function incomeStatementNormalizeAccountName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\bn\b/', 'and', $name) ?? $name;
    $name = str_replace(['&', '+'], ' and ', $name);
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;
    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
}

function incomeStatementExpenseBucket(string $accountName): ?array
{
    $name = incomeStatementNormalizeAccountName($accountName);
    $hasCostOfServicesPrefix = preg_match('/^(?:cos|cost of services?)\b/', $name) === 1;
    if ($hasCostOfServicesPrefix) {
        $name = trim(preg_replace('/^(?:cos|cost of services?)\b\s*/', '', $name) ?? $name);
    }

    // These names identify operating expenses unless they are explicitly marked as cost of services.
    if (!$hasCostOfServicesPrefix && preg_match('/^food and drinks? admin$|^fuel(?: mileage)?$/', $name) === 1) {
        return ['section' => 'operating_expenses', 'bucket' => $name === 'fuel' || $name === 'fuel mileage' ? 'fuel_mileage' : 'food_drinks_admin'];
    }

    $costOfServicesRules = [
        'product_cost' => '/\bproduct cost\b|\bcost of goods(?: sold)?\b|\bcogs\b|\bcost of sales\b/',
        'talent_fee' => '/\btalent fee\b|^tf$/',
        'product_manpower' => '/\bproduct manpower\b/',
        'rental_equipment' => '/\brental equipment\b|\bequipment rental\b/',
        'rental_studio_venue' => '/\brental (?:studio|venue)\b|\b(?:studio|venue) rental\b/',
        'other_cost' => '/\bother cost\b/',
        'other_expenses' => '/\bother expenses?\b/',
        'meal' => '/\bmeals?\b|\bfood and drinks?\b/',
        'transportation' => '/^transportation$|^transpo$/',
        'suppliers' => '/\bsuppliers?\b/',
        'creatives' => '/\bcreatives?\b/',
        'merchandise' => '/\bmerch(?:andise)?\b/',
        'van_services' => '/\bvan services?\b/',
        'fuel' => '/\bfuel\b/',
    ];
    foreach ($costOfServicesRules as $bucket => $pattern) {
        if (preg_match($pattern, $name) === 1) {
            return ['section' => 'cost_of_services', 'bucket' => $bucket];
        }
    }
    if ($hasCostOfServicesPrefix) {
        return ['section' => 'cost_of_services', 'bucket' => 'other_cost'];
    }

    $operatingRules = [
        'income_tax_expense' => '/^(?:income tax(?: expense)?|tax(?: expense)?)$/',
        'salary_premiums' => '/salary.*premium|wage.*premium|premium.*salary|premium.*wage/',
        'depreciation_equipment' => '/deprec|depreciat/',
        'food_drinks_admin' => '/food(?:s)? and drinks? admin|food drinks admin/',
        'office_rentals' => '/office rentals?/',
        'electricity_office' => '/electricity office/',
        'water_office' => '/water office/',
        'office_manpower' => '/office manpower/',
        'association_dues' => '/assoc dues|association dues/',
        'bank_fees' => '/bank fees?(?: and charges?)?|bank charges?/',
        'miscellaneous' => '/^(?:misc|miscellaneous)(?: expense)?$/',
        'parking' => '/parking/',
        'fuel_mileage' => '/fuel|mileage/',
        'repairs_maintenance' => '/repair|maintenance/',
        'taxes_licenses' => '/taxes and licenses|tax license|permits? and licenses?/',
        'office_supplies' => '/office supplies?|supplies?/',
        'representation' => '/representation/',
        'transportation_travel' => '/transportation and travel|transpo and travel|travel expense|delivery/',
        'communication' => '/communication|telephone|mobile phone/',
        'utilities' => '/^utilities(?: expense)?$/',
        'rent' => '/^rent(?: expense)?$/',
        'salaries_wages' => '/salaries and wages|salary and wages|wages and salaries|^salaries wages$/',
        'purchase' => '/^purchases?$|^purchase expense$/',
        'internet' => '/internet|broadband/',
        'marketing' => '/marketing|advertis/',
    ];
    foreach ($operatingRules as $bucket => $pattern) {
        if (preg_match($pattern, $name) === 1) {
            return ['section' => 'operating_expenses', 'bucket' => $bucket];
        }
    }

    return null;
}

function getIncomeStatementData(PDO $pdo, string $dateFrom, string $dateTo): array
{
    foreach ([$dateFrom, $dateTo] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Enter a valid income statement date range.');
        }
    }
    if ($dateFrom > $dateTo) {
        throw new InvalidArgumentException('The income statement start date must not be after the end date.');
    }

    $statementRows = $pdo->prepare(
        "SELECT a.id, a.name, at.name AS type_name,
                COALESCE(SUM(CASE
                    WHEN tj.id IS NULL THEN 0
                    WHEN at.name = 'Revenue' AND t.entry_type = 'credit' THEN t.amount
                    WHEN at.name = 'Revenue' THEN -t.amount
                    WHEN at.name = 'Expense' AND t.entry_type = 'debit' THEN t.amount
                    ELSE -t.amount
                END), 0) AS amount
         FROM accounts a
         JOIN account_types at ON at.id = a.account_type_id
         LEFT JOIN transactions t ON t.account_id = a.id
         LEFT JOIN transaction_journals tj
           ON tj.id = t.transaction_journal_id
          AND tj.transaction_date BETWEEN :date_from AND :date_to
          AND tj.status <> 'voided'
         WHERE at.name IN ('Revenue', 'Expense')
         GROUP BY a.id, a.name, at.name
         ORDER BY at.name, a.name"
    );
    $statementRows->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $accounts = $statementRows->fetchAll(PDO::FETCH_ASSOC);

    $revenueAmounts = [
        'Sales Revenue' => 0.0,
        'Service Revenue' => 0.0,
    ];
    $additionalRevenue = [];
    $costLabels = [
        'product_cost' => 'Cost of Services - Product Cost',
        'talent_fee' => 'Cost of Services - Talent Fee',
        'product_manpower' => 'Cost of Services - Product Manpower',
        'rental_equipment' => 'Cost of Services - Rental Equipment',
        'rental_studio_venue' => 'Cost of Services - Rental Studio / Venue',
        'other_cost' => 'Cost of Services - Other Cost',
        'other_expenses' => 'Cost of Services - Other Expenses',
        'meal' => 'Cost of Services - Meal',
        'transportation' => 'Cost of Services - Transportation',
        'suppliers' => 'Cost of Services - Suppliers',
        'creatives' => 'Cost of Services - Creatives',
        'merchandise' => 'Cost of Services - Merchandise',
        'van_services' => 'Cost of Services - Van Services',
        'fuel' => 'Cost of Services - Fuel',
    ];
    $operatingLabels = [
        'purchase' => 'Purchase Expense',
        'rent' => 'Rent Expense',
        'utilities' => 'Utilities Expense',
        'salaries_wages' => 'Salaries and Wages Expense',
        'salary_premiums' => 'Salaries and Wages Premiums',
        'bank_fees' => 'Bank Fees and Charges',
        'miscellaneous' => 'Miscellaneous Expense',
        'parking' => 'Parking',
        'fuel_mileage' => 'Fuel / Mileage Expense',
        'repairs_maintenance' => 'Repairs and Maintenance',
        'taxes_licenses' => 'Taxes and Licenses',
        'office_supplies' => 'Office Supplies',
        'office_rentals' => 'Office Rentals',
        'representation' => 'Representation',
        'transportation_travel' => 'Transportation and Travel',
        'communication' => 'Communication',
        'electricity_office' => 'Electricity - Office',
        'water_office' => 'Water - Office',
        'depreciation_equipment' => 'Depreciation Expense - Computer Equipment',
        'food_drinks_admin' => 'Food and Drinks - Administrative',
        'office_manpower' => 'Office Manpower',
        'internet' => 'Internet',
        'association_dues' => 'Association Dues',
        'marketing' => 'Marketing and Advertising Expense',
        'other_operating' => 'Other Operating Expenses',
    ];
    $costAmounts = array_fill_keys(array_keys($costLabels), 0.0);
    $operatingAmounts = array_fill_keys(array_keys($operatingLabels), 0.0);
    $incomeTaxExpense = 0.0;

    foreach ($accounts as $account) {
        $name = trim((string) $account['name']);
        $amount = statementMoney((float) $account['amount']);
        if ($account['type_name'] === 'Revenue') {
            $normalized = incomeStatementNormalizeAccountName($name);
            if (preg_match('/^sales(?: revenue)?$/', $normalized) === 1) {
                $revenueAmounts['Sales Revenue'] += $amount;
            } elseif (preg_match('/^service revenue$/', $normalized) === 1) {
                $revenueAmounts['Service Revenue'] += $amount;
            } else {
                $label = $name !== '' ? $name : 'Other Revenue';
                $additionalRevenue[$label] = ($additionalRevenue[$label] ?? 0.0) + $amount;
            }
            continue;
        }

        $bucket = incomeStatementExpenseBucket($name);
        if ($bucket === null) {
            $operatingAmounts['other_operating'] += $amount;
        } elseif ($bucket['bucket'] === 'income_tax_expense') {
            $incomeTaxExpense += $amount;
        } elseif ($bucket['section'] === 'cost_of_services') {
            $costAmounts[$bucket['bucket']] += $amount;
        } else {
            $operatingAmounts[$bucket['bucket']] += $amount;
        }
    }

    $revenueLines = [];
    foreach ($revenueAmounts as $name => $amount) {
        $revenueLines[] = ['name' => $name, 'amount' => statementMoney($amount)];
    }
    ksort($additionalRevenue, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($additionalRevenue as $name => $amount) {
        $revenueLines[] = ['name' => $name, 'amount' => statementMoney($amount)];
    }

    $costOfServices = [];
    foreach ($costLabels as $key => $label) {
        $costOfServices[] = ['name' => $label, 'amount' => statementMoney($costAmounts[$key])];
    }
    $operatingExpenses = [];
    foreach ($operatingLabels as $key => $label) {
        $operatingExpenses[] = ['name' => $label, 'amount' => statementMoney($operatingAmounts[$key])];
    }

    $totalRevenue = statementMoney(array_sum(array_column($revenueLines, 'amount')));
    $totalCostOfServices = statementMoney(array_sum(array_column($costOfServices, 'amount')));
    $grossProfit = statementMoney($totalRevenue - $totalCostOfServices);
    $totalOperatingExpenses = statementMoney(array_sum(array_column($operatingExpenses, 'amount')));
    $netIncomeBeforeTax = statementMoney($grossProfit - $totalOperatingExpenses);
    $incomeTaxExpense = statementMoney($incomeTaxExpense);
    $netIncomeAfterTax = statementMoney($netIncomeBeforeTax - $incomeTaxExpense);
    $businessName = trim((string) $pdo->query('SELECT business_name FROM business_settings WHERE id = 1')->fetchColumn());

    return [
        'business_name' => $businessName !== '' && $businessName !== 'My Business' ? $businessName : 'WRS Accounting / KoboSocial',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'revenue_lines' => $revenueLines,
        'total_revenue' => $totalRevenue,
        'cost_of_services' => $costOfServices,
        'total_cost_of_services' => $totalCostOfServices,
        'gross_profit' => $grossProfit,
        'operating_expenses' => $operatingExpenses,
        'total_operating_expenses' => $totalOperatingExpenses,
        'net_income_before_tax' => $netIncomeBeforeTax,
        'income_tax_expense' => $incomeTaxExpense,
        'net_income_after_tax' => $netIncomeAfterTax,
        'summary' => [
            ['name' => 'Total Revenue', 'amount' => $totalRevenue],
            ['name' => 'Gross Profit', 'amount' => $grossProfit],
            ['name' => 'Total Operating Expenses', 'amount' => $totalOperatingExpenses],
            ['name' => $netIncomeAfterTax >= 0 ? 'Net Income After Tax' : 'Net Loss After Tax', 'amount' => $netIncomeAfterTax],
        ],
    ];
}

function getBalanceSheetData(PDO $pdo, string $asOf): array
{
    // Inactive accounts can still carry valid ledger balances and belong on the report.
    $accounts = getAccountBalancesAsOf($pdo, $asOf);
    $currentAssets = [
        'Cash' => 0.0,
        'Cash in Bank' => 0.0,
        'Accounts Receivable' => 0.0,
        'Project Cash Advance' => 0.0,
        'Prepaid Expense' => 0.0,
        'Inventory' => 0.0,
        'Other Current Assets' => 0.0,
    ];
    $nonCurrentAssets = [
        'Property and Equipment' => 0.0,
        'Accumulated Depreciation' => 0.0,
        'Other Non-Current Assets' => 0.0,
    ];
    $currentLiabilities = [
        'Accounts Payable' => 0.0,
        'Salaries Payable' => 0.0,
        'Unearned Revenue' => 0.0,
        'Other Current Liabilities' => 0.0,
    ];
    $nonCurrentLiabilities = [
        'Notes Payable' => 0.0,
        'Other Non-Current Liabilities' => 0.0,
    ];
    $capital = 0.0;
    $retainedEarnings = 0.0;
    $ownerDrawingBalance = 0.0;
    $otherEquity = 0.0;

    foreach ($accounts as $account) {
        $name = strtolower(trim((string) $account['name']));
        $balance = statementMoney((float) $account['balance']);
        $type = (string) $account['type_name'];

        if ($type === 'Asset') {
            if (preg_match('/accumulated\s+depreciation/', $name) === 1) {
                $nonCurrentAssets['Accumulated Depreciation'] -= abs($balance);
            } elseif (preg_match('/project\s+cash\s+advance/', $name) === 1) {
                $currentAssets['Project Cash Advance'] += $balance;
            } elseif (preg_match('/prepaid/', $name) === 1) {
                $currentAssets['Prepaid Expense'] += $balance;
            } elseif (preg_match('/accounts?\s+receivable|trade\s+receivable/', $name) === 1) {
                $currentAssets['Accounts Receivable'] += $balance;
            } elseif (preg_match('/inventor|\bstock\b/', $name) === 1) {
                $currentAssets['Inventory'] += $balance;
            } elseif ((int) $account['is_cash_account'] === 1 || preg_match('/\bcash\b|e[- ]?wallet/', $name) === 1) {
                if (preg_match('/\bbank\b/', $name) === 1) {
                    $currentAssets['Cash in Bank'] += $balance;
                } else {
                    $currentAssets['Cash'] += $balance;
                }
            } elseif ($account['classification'] === 'non_current'
                || preg_match('/property|equipment|machinery|vehicle|furniture|building|\bland\b/', $name) === 1) {
                if (preg_match('/property|equipment|machinery|vehicle|furniture|building|\bland\b/', $name) === 1) {
                    $nonCurrentAssets['Property and Equipment'] += $balance;
                } else {
                    $nonCurrentAssets['Other Non-Current Assets'] += $balance;
                }
            } else {
                $currentAssets['Other Current Assets'] += $balance;
            }
            continue;
        }

        if ($type === 'Liability') {
            if (preg_match('/notes payable|long[- ]term debt|long[- ]term loan/', $name) === 1) {
                $nonCurrentLiabilities['Notes Payable'] += $balance;
            } elseif (preg_match('/accounts payable|trade payable|vendor payable/', $name) === 1) {
                $currentLiabilities['Accounts Payable'] += $balance;
            } elseif (preg_match('/salaries payable|wages payable/', $name) === 1) {
                $currentLiabilities['Salaries Payable'] += $balance;
            } elseif (preg_match('/unearned revenue|deferred revenue/', $name) === 1) {
                $currentLiabilities['Unearned Revenue'] += $balance;
            } elseif ($account['classification'] === 'non_current') {
                $nonCurrentLiabilities['Other Non-Current Liabilities'] += $balance;
            } else {
                $currentLiabilities['Other Current Liabilities'] += $balance;
            }
            continue;
        }

        if ($type === 'Equity') {
            if (preg_match('/retained earnings/', $name) === 1) {
                $retainedEarnings += $balance;
            } elseif (preg_match('/owner.?s?\s+(drawing|drawings|draw)|\bdrawings?\b/', $name) === 1) {
                $ownerDrawingBalance += $balance;
            } elseif (preg_match('/owner.?s?\s+capital|owners capital|capital invested|owner.?s?\s+equity/', $name) === 1) {
                $capital += $balance;
            } else {
                $otherEquity += $balance;
            }
        }
    }

    $sections = [&$currentAssets, &$nonCurrentAssets, &$currentLiabilities, &$nonCurrentLiabilities];
    foreach ($sections as &$section) {
        foreach ($section as &$amount) {
            $amount = statementMoney($amount);
        }
        unset($amount);
    }
    unset($section, $sections);

    $totalCurrentAssets = statementMoney(array_sum($currentAssets));
    $totalNonCurrentAssets = statementMoney(array_sum($nonCurrentAssets));
    $totalAssets = statementMoney($totalCurrentAssets + $totalNonCurrentAssets);
    $totalCurrentLiabilities = statementMoney(array_sum($currentLiabilities));
    $totalNonCurrentLiabilities = statementMoney(array_sum($nonCurrentLiabilities));
    $totalLiabilities = statementMoney($totalCurrentLiabilities + $totalNonCurrentLiabilities);

    // A balance sheet is point-in-time; include cumulative net income after tax through the cutoff.
    $incomeStatement = getIncomeStatementData($pdo, '1000-01-01', $asOf);
    $netIncome = statementMoney((float) $incomeStatement['net_income_after_tax']);
    // Equity accounts normally carry a credit balance; drawings therefore reduce equity with a negative balance.
    $drawingLineAmount = statementMoney(-$ownerDrawingBalance);
    // Present current and accumulated earnings together as Retained Earnings.
    $reportedRetainedEarnings = statementMoney($retainedEarnings + $netIncome);
    $equityLines = ["Owner's Capital" => statementMoney($capital)];
    $equityLines['Retained Earnings'] = $reportedRetainedEarnings;
    if (abs($drawingLineAmount) >= 0.005) {
        $equityLines[$drawingLineAmount >= 0 ? "Less: Owner's Drawing" : "Add: Owner's Drawing Credit"] = abs($drawingLineAmount);
    } else {
        $equityLines["Less: Owner's Drawing"] = 0.0;
    }
    if (abs($otherEquity) >= 0.005) {
        $equityLines['Other Equity'] = statementMoney($otherEquity);
    }

    $totalEquity = statementMoney($capital + $reportedRetainedEarnings + $ownerDrawingBalance + $otherEquity);
    $totalLiabilitiesAndEquity = statementMoney($totalLiabilities + $totalEquity);
    $balanceDifference = statementMoney($totalAssets - $totalLiabilitiesAndEquity);
    $isBalanced = abs($balanceDifference) < 0.01;
    $balanceStatus = $isBalanced
        ? 'Balanced'
        : 'Out of balance by ₱' . number_format(abs($balanceDifference), 2);
    $businessName = trim((string) $pdo->query('SELECT business_name FROM business_settings WHERE id = 1')->fetchColumn());

    return [
        'business_name' => $businessName !== '' && $businessName !== 'My Business' ? $businessName : 'WRS Accounting / KoboSocial',
        'as_of' => $asOf,
        'current_assets' => $currentAssets,
        'total_current_assets' => $totalCurrentAssets,
        'non_current_assets' => $nonCurrentAssets,
        'total_non_current_assets' => $totalNonCurrentAssets,
        'total_assets' => $totalAssets,
        'current_liabilities' => $currentLiabilities,
        'total_current_liabilities' => $totalCurrentLiabilities,
        'non_current_liabilities' => $nonCurrentLiabilities,
        'total_non_current_liabilities' => $totalNonCurrentLiabilities,
        'total_liabilities' => $totalLiabilities,
        'equity' => $equityLines,
        'total_equity' => $totalEquity,
        'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
        'net_income' => $netIncome,
        'is_balanced' => $isBalanced,
        'balance_difference' => $balanceDifference,
        'balance_status' => $balanceStatus,
        'summary' => [
            ['name' => 'Total Assets', 'amount' => $totalAssets],
            ['name' => 'Total Liabilities', 'amount' => $totalLiabilities],
            ['name' => "Total Owner's Equity", 'amount' => $totalEquity],
            ['name' => 'Balance Status', 'value' => $balanceStatus],
        ],
    ];
}
