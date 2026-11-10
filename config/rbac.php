<?php
/**
 * WorkNest HR — roles (categories a login can hold).
 *
 * Access is decided in two layers (lib/modules.php):
 *   1. the module must be ON for the organisation (Admin → Modules);
 *   2. the login must hold a role allowed for that module, or have a per-person "allow" — and no per-person "deny".
 * Inside a module, data classes below decide which fields leave the server.
 *
 * The OWNER (users.is_owner = 1, created by install.php) always holds SUPERADMIN, passes every check of the
 * administration modules, and can never be demoted, deactivated or deleted — by anyone, including another Super Admin.
 */
declare(strict_types=1);

return [
    'roles' => [
        'SUPERADMIN' => ['label' => 'Super Admin', 'note' => 'Governance: organisation settings, modules, users, audit.'],
        'CEO'        => ['label' => 'CEO / Director', 'note' => 'Final approvals (payroll, claims, letters), company-wide view.'],
        'HRMANAGER'  => ['label' => 'HR Manager', 'note' => 'All HR operations and HR desks; creates employee / manager logins.'],
        'HROPS'      => ['label' => 'HR Operations', 'note' => 'Records, verifications, attendance and leave desks.'],
        'HRRECRUIT'  => ['label' => 'HR Recruitment', 'note' => 'Recruitment, careers page, candidate portal.'],
        'HRPERF'     => ['label' => 'HR Performance & Training', 'note' => 'KRA sheets, reviews, training register.'],
        'MANAGER'    => ['label' => 'Manager', 'note' => 'Approves their team\'s leave and requests; sees their team only.'],
        'EMPLOYEE'   => ['label' => 'Employee', 'note' => 'Own records only, deny by default.'],
        'ACCOUNTS'   => ['label' => 'Accounts / Payment', 'note' => 'Pays approved claims and bills; statutory payments.'],
        'ITADMIN'    => ['label' => 'IT Admin', 'note' => 'Asset register, IT accounts, email settings.'],
        'RECEPTION'  => ['label' => 'Reception / Security', 'note' => 'Visitor desk and gate log only.'],
        'PRODHEAD'   => ['label' => 'Production Head', 'note' => 'Production hours and staff movement for ticked branches.'],
        'SALES'      => ['label' => 'Sales', 'note' => 'CRM, enquiries, quotations (own records unless widened).'],
        'MARKETING'  => ['label' => 'Marketing', 'note' => 'Campaigns, leads, ad spend.'],
        'QUALITY'    => ['label' => 'Quality', 'note' => 'Complaints and CAPA.'],
        'STORE'      => ['label' => 'Store', 'note' => 'Stock in / out, transfers, counts.'],
        'BUYER'      => ['label' => 'Buyer', 'note' => 'Requisitions, vendors, purchase orders.'],
    ],

    /* Roles allowed to see each data class. Anything not listed is refused on the server. */
    'data' => [
        'basic'    => ['*'],                                         // name, code, designation, department, branch, manager
        'contact'  => ['*'],                                         // work email / phone
        'personal' => ['SUPERADMIN', 'HRMANAGER', 'HROPS'],          // DOB, personal contact, address, family
        'identity' => ['HRMANAGER', 'HROPS'],                        // ID numbers — masked in every list
        'pay'      => ['SUPERADMIN', 'CEO'],                         // salary: widened only by the Super Admin, person by person
        'bank'     => ['CEO', 'ACCOUNTS'],
    ],

    /* Who may give which roles on Users & access. The owner may give anything. */
    'assignable' => [
        'SUPERADMIN' => '*',
        'HRMANAGER'  => ['MANAGER', 'EMPLOYEE', 'RECEPTION', 'PRODHEAD', 'HROPS', 'HRRECRUIT', 'HRPERF'],
    ],
];
