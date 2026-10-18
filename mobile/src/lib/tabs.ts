/**
 * Pestañas de la app según los permisos del rol (config/permissions.php):
 *  - Técnico (field.work):        Jornada · Tickets · Órdenes · Clientes · Perfil
 *  - Cobrador (collection.field): Cobranza · Corte · Clientes · Perfil
 * Si un usuario tiene ambas funciones (admin/supervisor) y no caben 5, Clientes y Corte
 * siguen accesibles desde Perfil y Cobranza.
 */
export type TabName = 'jornada' | 'tickets' | 'ordenes' | 'cobranza' | 'corte' | 'clientes' | 'perfil';

export function visibleTabs(can: (...a: string[]) => boolean): TabName[] {
  const tabs: TabName[] = [];
  if (can('field.work')) tabs.push('jornada');
  if (can('field.work') && can('tickets.view')) tabs.push('tickets');
  if (can('field.work')) tabs.push('ordenes');
  if (can('collection.field')) tabs.push('cobranza', 'corte');
  if (can('clients.view')) tabs.push('clientes');
  tabs.push('perfil');

  for (const drop of ['clientes', 'corte'] as TabName[]) {
    if (tabs.length > 5 && tabs.includes(drop)) tabs.splice(tabs.indexOf(drop), 1);
  }
  return tabs;
}
