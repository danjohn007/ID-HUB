/** Formatos iguales a los del panel web (helpers.php money(), fechas d/m/Y en español). */

const DAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** money(1234.5) → "$1,234.50"; money(1234.5, false) → "$1,235". */
export function money(amount: number | string | null | undefined, decimals = true): string {
  const n = Number(amount ?? 0) || 0;
  const fixed = Math.abs(n).toFixed(decimals ? 2 : 0);
  const [int, dec] = fixed.split('.');
  const withCommas = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${n < 0 ? '-$' : '$'}${withCommas}${dec ? '.' + dec : ''}`;
}

const pad = (n: number) => String(n).padStart(2, '0');

/** "2026-10-13" o ISO → Date local (las fechas simples se toman como fecha local, sin zona). */
export function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null;
  if (value instanceof Date) return value;
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, m - 1, d);
  }
  const d = new Date(value);
  return isNaN(d.getTime()) ? null : d;
}

/** 13/10/2026 */
export function date(value: string | Date | null | undefined): string {
  const d = toDate(value);
  return d ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}` : '';
}

/** 13/10 */
export function shortDate(value: string | Date | null | undefined): string {
  const d = toDate(value);
  return d ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}` : '';
}

/** 14:05 */
export function time(value: string | Date | null | undefined): string {
  const d = toDate(value);
  return d ? `${pad(d.getHours())}:${pad(d.getMinutes())}` : '';
}

/** 13/10/2026 14:05 */
export function dateTime(value: string | Date | null | undefined): string {
  const d = toDate(value);
  return d ? `${date(d)} ${time(d)}` : '';
}

/** 13/10 14:05 (historial, como la web) */
export function shortDateTime(value: string | Date | null | undefined): string {
  const d = toDate(value);
  return d ? `${shortDate(d)} ${time(d)}` : '';
}

/** "Lunes 13 de octubre" (encabezados de jornada). */
export function longToday(d = new Date()): string {
  const s = `${DAYS[d.getDay()]} ${pad(d.getDate())} de ${MONTHS[d.getMonth()]}`;
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** "6 de noviembre" */
export function dayMonth(value: string | Date | null | undefined): string {
  const d = toDate(value);
  return d ? `${d.getDate()} de ${MONTHS[d.getMonth()]}` : '';
}

/** YYYY-MM-DD local */
export function isoDate(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** 850 m / 2.4 km */
export function distance(km: number | null | undefined): string {
  if (km === null || km === undefined) return '';
  return km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(1)} km`;
}

/** Iniciales para el avatar (initials() del helper web). */
export function initials(name?: string | null): string {
  const parts = (name ?? '').trim().split(/\s+/);
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase();
}

export function firstName(name?: string | null): string {
  return (name ?? '').trim().split(/\s+/)[0] ?? '';
}

/** Cantidad sin ceros sobrantes (InventoryService::qty). */
export function qty(n: number): string {
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}
