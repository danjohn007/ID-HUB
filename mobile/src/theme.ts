/**
 * Identidad WISPCONTROL: mismos tokens que resources/css/app.css del panel web
 * (brand = azul primario, navy = barra lateral/encabezados, colores semánticos de estados).
 */
export const colors = {
  brand: {
    50: '#eef6ff', 100: '#d9eaff', 200: '#bcdbff', 300: '#8ec4ff', 400: '#59a3fb',
    500: '#3390f8', 600: '#1a7ff5', 700: '#1366d1', 800: '#1652a8', 900: '#184784',
  },
  navy: {
    50: '#f1f5fb', 100: '#dfe7f3', 200: '#b9c8e0', 300: '#8aa2c7', 400: '#5b78a6', 500: '#3d5a88',
    600: '#2a4470', 700: '#1c3259', 800: '#122545', 900: '#0b1b3a', 950: '#071330',
  },
  success: { 50: '#ecfbf3', 100: '#d1f5e1', 500: '#2bb57a', 600: '#22a06b', 700: '#1a7f55' },
  danger: { 50: '#fff0f0', 100: '#ffdcdd', 500: '#ee5b60', 600: '#e5484d', 700: '#c4373c' },
  warning: { 50: '#fff6eb', 100: '#ffe8cc', 500: '#f99a3d', 600: '#f7881f', 700: '#d06c0c' },
  teal: { 50: '#e6f8f8', 100: '#c4eeef', 600: '#00a0a8', 700: '#008188' },
  purple: { 50: '#f2f0fd', 100: '#e2ddfa', 600: '#6048d0', 700: '#4d37b0' },
  slate: {
    50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0', 300: '#cbd5e1', 400: '#94a3b8',
    500: '#64748b', 600: '#475569', 700: '#334155', 800: '#1e293b', 900: '#0f172a',
  },
  surface: '#f4f7fb',
  white: '#ffffff',
};

/** Colores semánticos de los catálogos de config/wisp.php ("success", "danger", "info"...). */
export type Tone = 'brand' | 'info' | 'success' | 'danger' | 'warning' | 'purple' | 'teal' | 'slate' | 'navy';

/** <x-ui.badge>: fondo, texto, borde y punto por color. */
export const badgeTones: Record<Tone, { bg: string; fg: string; ring: string; dot: string }> = {
  brand: { bg: colors.brand[50], fg: colors.brand[700], ring: colors.brand[200], dot: colors.brand[500] },
  info: { bg: colors.brand[50], fg: colors.brand[700], ring: colors.brand[200], dot: colors.brand[500] },
  success: { bg: colors.success[50], fg: colors.success[700], ring: colors.success[100], dot: colors.success[600] },
  danger: { bg: colors.danger[50], fg: colors.danger[700], ring: colors.danger[100], dot: colors.danger[600] },
  warning: { bg: colors.warning[50], fg: colors.warning[700], ring: colors.warning[100], dot: colors.warning[600] },
  purple: { bg: colors.purple[50], fg: colors.purple[700], ring: colors.purple[100], dot: colors.purple[600] },
  teal: { bg: colors.teal[50], fg: colors.teal[700], ring: colors.teal[100], dot: colors.teal[600] },
  slate: { bg: colors.slate[100], fg: colors.slate[600], ring: colors.slate[200], dot: colors.slate[400] },
  navy: { bg: colors.navy[900], fg: colors.white, ring: colors.navy[900], dot: colors.white },
};

/** Color sólido (botones, tarjetas KPI <x-ui.stat>). */
export const solidTones: Record<Tone, string> = {
  brand: colors.brand[600], info: colors.brand[600], success: colors.success[600], danger: colors.danger[600],
  warning: colors.warning[600], purple: colors.purple[600], teal: colors.teal[600], slate: colors.slate[500], navy: colors.navy[800],
};

export const toTone = (color?: string | null): Tone =>
  (color && color in badgeTones ? color : 'slate') as Tone;

/** Inter, la misma tipografía del panel (@fontsource-variable/inter). */
export const fonts = {
  regular: 'Inter_400Regular',
  medium: 'Inter_500Medium',
  semibold: 'Inter_600SemiBold',
  bold: 'Inter_700Bold',
  extrabold: 'Inter_800ExtraBold',
};

export const radius = { sm: 8, md: 12, lg: 16, xl: 20, full: 999 };

/** Sombra suave de .card (shadow 0 1px 2px rgba(16,24,40,.04)). */
export const shadow = {
  card: { shadowColor: '#101828', shadowOpacity: 0.06, shadowRadius: 3, shadowOffset: { width: 0, height: 1 }, elevation: 1 },
  lg: { shadowColor: '#0b1b3a', shadowOpacity: 0.18, shadowRadius: 12, shadowOffset: { width: 0, height: 6 }, elevation: 6 },
};
