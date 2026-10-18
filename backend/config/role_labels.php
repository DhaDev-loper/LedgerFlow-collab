<?php
declare(strict_types=1);

function roleDisplayName(string $role): string
{
    $titles = [
        'owner' => 'CEO',
        'admin' => 'CFO',
        'accountant' => 'Senior Accountant',
        'staff' => 'Accounts Executive',
    ];

    return $titles[$role] ?? ucfirst($role);
}
