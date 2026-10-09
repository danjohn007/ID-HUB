<?php

/*
|--------------------------------------------------------------------------
| Matriz de permisos por rol
|--------------------------------------------------------------------------
| El superadministrador tiene acceso total (Gate::before). Las rutas usan
| middleware('can:<permiso>') y las vistas @can('<permiso>').
*/

return [
    'abilities' => [
        'dashboard.view' => 'Ver dashboard',
        'finance.view' => 'Ver indicadores financieros',
        'clients.view' => 'Ver clientes',
        'clients.manage' => 'Crear/editar clientes',
        'service.control' => 'Suspender/reactivar servicio',
        'plans.manage' => 'Administrar planes',
        'billing.view' => 'Ver cobranza',
        'billing.manage' => 'Registrar cargos y pagos',
        'cash.reconcile' => 'Conciliar cortes de caja',
        'collection.field' => 'Cobranza en campo',
        'network.view' => 'Ver red',
        'network.manage' => 'Administrar red y MikroTik',
        'map.view' => 'Ver mapa',
        'prospects.manage' => 'Prospectos',
        'installations.manage' => 'Órdenes de instalación',
        'tickets.view' => 'Ver tickets',
        'tickets.manage' => 'Administrar tickets',
        'field.work' => 'Trabajo de campo (técnico)',
        'staff.view' => 'Ver técnicos y cobradores',
        'routes.manage' => 'Planear rutas',
        'inventory.view' => 'Ver inventario',
        'inventory.manage' => 'Movimientos de inventario',
        'communications.manage' => 'Mensajes y campañas',
        'automations.manage' => 'Automatizaciones',
        'growth.manage' => 'Promociones y referidos',
        'captive.manage' => 'Portal cautivo',
        'reports.view' => 'Reportes',
        'bi.view' => 'Business Intelligence',
        'alerts.view' => 'Alertas',
        'audit.view' => 'Auditoría',
        'users.manage' => 'Usuarios',
        'settings.manage' => 'Configuración de la empresa',
    ],

    'roles' => [
        'admin' => ['*'],
        'finanzas' => [
            'dashboard.view', 'finance.view', 'clients.view', 'billing.view', 'billing.manage', 'cash.reconcile',
            'service.control', 'reports.view', 'bi.view', 'growth.manage', 'communications.manage', 'alerts.view', 'plans.manage',
        ],
        'soporte' => [
            'dashboard.view', 'clients.view', 'clients.manage', 'tickets.view', 'tickets.manage', 'network.view',
            'map.view', 'prospects.manage', 'installations.manage', 'alerts.view', 'billing.view', 'staff.view',
        ],
        'tecnico' => [
            'field.work', 'tickets.view', 'clients.view', 'map.view', 'network.view', 'inventory.view',
        ],
        'cobrador' => [
            'collection.field', 'clients.view',
        ],
        'supervisor' => [
            'dashboard.view', 'clients.view', 'tickets.view', 'tickets.manage', 'network.view', 'map.view', 'staff.view',
            'routes.manage', 'installations.manage', 'inventory.view', 'inventory.manage', 'cash.reconcile', 'billing.view',
            'alerts.view', 'reports.view', 'prospects.manage', 'field.work',
        ],
    ],
];
