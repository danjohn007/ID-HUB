<?php

/*
|--------------------------------------------------------------------------
| Menú lateral
|--------------------------------------------------------------------------
| Cada ítem: [label, route, icon (heroicon-o-*), ability, active pattern, insignia opcional].
| La insignia es una clave de $badges en components/layouts/app.blade.php (contador junto al texto).
| Los ítems cuya ruta no existe o sin permiso se ocultan automáticamente.
*/

return [
    'tenant' => [
        ['group' => null, 'items' => [
            ['Dashboard', 'dashboard', 'home', 'dashboard.view', 'dashboard'],
            ['Mi jornada', 'field.today', 'wrench-screwdriver', 'field.work', 'field.today*'],
            ['Mi cobranza', 'field.collection', 'banknotes', 'collection.field', 'field.collection*'],
        ]],
        ['group' => 'Clientes', 'items' => [
            ['Clientes', 'clients.index', 'users', 'clients.view', 'clients.*'],
            ['Prospectos', 'prospects.index', 'user-plus', 'prospects.manage', 'prospects.*'],
            ['Instalaciones', 'installations.index', 'clipboard-document-check', 'installations.manage', 'installations.*'],
        ]],
        ['group' => 'Cobranza', 'items' => [
            ['Cobranza', 'billing.index', 'credit-card', 'billing.view', 'billing.index'],
            ['Pagos', 'billing.payments', 'receipt-percent', 'billing.view', 'billing.payments*'],
            ['Cobradores', 'billing.collections', 'map-pin', 'billing.view', 'billing.collections*'],
            ['Cortes de caja', 'billing.cash', 'calculator', 'cash.reconcile', 'billing.cash*'],
        ]],
        ['group' => 'Red', 'items' => [
            ['Monitoreo', 'network.index', 'signal', 'network.view', 'network.index'],
            ['Repetidores', 'network.repeaters.index', 'wifi', 'network.view', 'network.repeaters.*'],
            ['Equipos', 'network.devices.index', 'server-stack', 'network.view', 'network.devices.*'],
            ['MikroTik', 'network.mikrotik.index', 'cpu-chip', 'network.manage', 'network.mikrotik.*'],
            ['Torres y cobertura', 'network.towers.index', 'building-office-2', 'network.view', 'network.towers.*'],
            ['Balanceo', 'network.balancing', 'scale', 'network.manage', 'network.balancing'],
            ['Mapa', 'map.index', 'map', 'map.view', 'map.*'],
        ]],
        ['group' => 'Operación', 'items' => [
            ['Tickets', 'tickets.index', 'ticket', 'tickets.view', 'tickets.*'],
            ['Técnicos y cobradores', 'staff.index', 'identification', 'staff.view', 'staff.*'],
            ['Rutas', 'routes.index', 'truck', 'routes.manage', 'routes.*'],
            ['Inventario', 'inventory.index', 'archive-box', 'inventory.view', 'inventory.*'],
            ['Mantenimientos', 'maintenances.index', 'calendar-days', 'network.manage', 'maintenances.*'],
        ]],
        ['group' => 'Comunicación', 'items' => [
            ['Mensajes', 'communications.messages', 'chat-bubble-left-right', 'communications.manage', 'communications.messages'],
            ['Campañas', 'communications.campaigns', 'megaphone', 'communications.manage', 'communications.campaigns*'],
            ['Plantillas', 'communications.templates', 'document-text', 'communications.manage', 'communications.templates'],
            ['Automatizaciones', 'automations.index', 'bolt', 'automations.manage', 'automations.*'],
            ['Alertas', 'alerts.index', 'bell-alert', 'alerts.view', 'alerts.*'],
        ]],
        ['group' => 'Crecimiento', 'items' => [
            ['Planes', 'plans.index', 'rectangle-stack', 'plans.manage', 'plans.*'],
            ['Promociones', 'growth.promotions', 'gift', 'growth.manage', 'growth.promotions'],
            ['Referidos', 'growth.referrals', 'user-group', 'growth.manage', 'growth.referrals'],
            ['Portal cautivo', 'captive.index', 'globe-alt', 'captive.manage', 'captive.*'],
        ]],
        ['group' => 'Análisis', 'items' => [
            ['Finanzas', 'finance.index', 'chart-bar', 'finance.view', 'finance.*'],
            ['Business Intelligence', 'bi.index', 'presentation-chart-line', 'bi.view', 'bi.*'],
            ['Reportes', 'reports.index', 'document-chart-bar', 'reports.view', 'reports.*'],
            ['Auditoría', 'audit.index', 'shield-check', 'audit.view', 'audit.*'],
        ]],
        ['group' => 'Configuración', 'items' => [
            ['Empresa', 'settings.index', 'cog-6-tooth', 'settings.manage', 'settings.*'],
            ['Usuarios', 'users.index', 'key', 'users.manage', 'users.*'],
        ]],
    ],

    'saas' => [
        ['group' => 'Plataforma SaaS', 'items' => [
            ['Panel SaaS', 'saas.dashboard', 'globe-americas', 'saas.manage', 'saas.dashboard'],
            ['Empresas WISP', 'saas.tenants.index', 'building-office', 'saas.manage', 'saas.tenants.*'],
            ['Planes SaaS', 'saas.plans', 'rectangle-group', 'saas.manage', 'saas.plans'],
            ['Comisiones', 'saas.commissions', 'banknotes', 'saas.manage', 'saas.commissions'],
            ['Sistema', 'saas.system', 'wrench', 'saas.manage', 'saas.system'],
        ]],
        ['group' => 'Ventas', 'items' => [
            ['Prospectos', 'saas.sales.leads', 'user-group', 'saas.manage', 'saas.sales.leads*', 'demo_requests'],
            ['Agenda de demos', 'saas.sales.agenda', 'calendar-days', 'saas.manage', 'saas.sales.agenda', 'demos_today'],
        ]],
    ],
];
