import { useAuth } from './auth';

import { colors } from '@/theme';

/**
 * Color primario: el del WISP sólo si su plan incluye marca blanca (igual que el layout web,
 * que sobrescribe --color-brand-600 con tenant()->primary_color cuando tiene "white_label").
 */
export function useBrand() {
  const { session } = useAuth();
  const t = session?.tenant;
  const custom = t?.white_label && t.primary_color && /^#[0-9a-f]{6}$/i.test(t.primary_color) ? t.primary_color : null;

  return {
    primary: custom ?? colors.brand[600],
    whiteLabel: !!custom,
    logoUrl: t?.white_label ? t.logo_url : null,
    tenantName: t?.name ?? 'WISPCONTROL',
  };
}
