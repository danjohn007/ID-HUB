import { ComponentType, ReactNode } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleProp,
  StyleSheet,
  Text as RNText,
  TextInput,
  TextInputProps,
  TextProps,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { useBrand } from '@/lib/brand';
import { initials } from '@/lib/format';
import type { Catalog } from '@/lib/types';
import { badgeTones, colors, fonts, radius, shadow, solidTones, Tone, toTone } from '@/theme';

/**
 * Kit de UI de la app = <x-ui.*> del panel web (badge, button, card, stat, input, tabs, modal, empty, avatar).
 */

type IconType = ComponentType<{ size?: number; color?: string }>;

// ------------------------------------------------------------------ Texto

type Weight = 'regular' | 'medium' | 'semibold' | 'bold' | 'extrabold';

export function Text({ weight = 'regular', style, ...props }: TextProps & { weight?: Weight }) {
  return <RNText {...props} style={[{ fontFamily: fonts[weight], color: colors.slate[800], fontSize: 14 }, style]} />;
}

// ------------------------------------------------------------------ Badge / estado

export function Badge({ tone = 'slate', dot, size = 'sm', children, style }: { tone?: Tone; dot?: boolean; size?: 'xs' | 'sm'; children: ReactNode; style?: StyleProp<ViewStyle> }) {
  const t = badgeTones[tone];
  return (
    <View style={[styles.badge, { backgroundColor: t.bg, borderColor: t.ring }, size === 'xs' ? styles.badgeXs : null, style]}>
      {dot ? <View style={[styles.badgeDot, { backgroundColor: t.dot }]} /> : null}
      <Text weight="semibold" style={{ color: t.fg, fontSize: size === 'xs' ? 10.5 : 12 }} numberOfLines={1}>
        {children}
      </Text>
    </View>
  );
}

/** <x-ui.status>: catálogo { value, label, color } del API. */
export function Status({ value, size = 'xs', dot = true }: { value?: Catalog | null; size?: 'xs' | 'sm'; dot?: boolean }) {
  if (!value?.label) return null;
  return (
    <Badge tone={toTone(value.color)} size={size} dot={dot}>
      {value.label}
    </Badge>
  );
}

// ------------------------------------------------------------------ Botón

type Variant = 'primary' | 'secondary' | 'danger' | 'success' | 'warning' | 'ghost' | 'white' | 'soft' | 'teal';

export function Button({
  children,
  onPress,
  variant = 'primary',
  size = 'md',
  icon: Icon,
  loading,
  disabled,
  style,
  full,
}: {
  children: ReactNode;
  onPress?: () => void;
  variant?: Variant;
  size?: 'sm' | 'md' | 'lg';
  icon?: IconType;
  loading?: boolean;
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
  full?: boolean;
}) {
  const brand = useBrand();
  const palette: Record<Variant, { bg: string; fg: string; border?: string }> = {
    primary: { bg: brand.primary, fg: colors.white },
    secondary: { bg: colors.white, fg: colors.slate[700], border: colors.slate[300] },
    danger: { bg: colors.danger[600], fg: colors.white },
    success: { bg: colors.success[600], fg: colors.white },
    warning: { bg: colors.warning[600], fg: colors.white },
    teal: { bg: colors.teal[600], fg: colors.white },
    ghost: { bg: 'transparent', fg: colors.slate[600] },
    white: { bg: colors.white, fg: colors.navy[900] },
    soft: { bg: colors.brand[50], fg: colors.brand[700], border: colors.brand[200] },
  };
  const p = palette[variant];
  const pad = size === 'sm' ? styles.btnSm : size === 'lg' ? styles.btnLg : styles.btnMd;
  const fontSize = size === 'sm' ? 12.5 : size === 'lg' ? 15 : 14;
  const iconSize = size === 'sm' ? 16 : 20;
  const off = disabled || loading;

  return (
    <Pressable
      onPress={off ? undefined : onPress}
      style={({ pressed }) => [
        styles.btn,
        pad,
        { backgroundColor: p.bg, borderColor: p.border ?? p.bg, borderWidth: p.border ? 1 : 0 },
        variant !== 'ghost' && variant !== 'secondary' ? shadow.card : null,
        full ? { alignSelf: 'stretch' } : null,
        off ? { opacity: 0.6 } : null,
        pressed && !off ? { transform: [{ scale: 0.98 }], opacity: 0.92 } : null,
        style,
      ]}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!off, busy: !!loading }}
    >
      {loading ? <ActivityIndicator size="small" color={p.fg} /> : Icon ? <Icon size={iconSize} color={p.fg} /> : null}
      {typeof children === 'string' ? (
        <Text weight={size === 'lg' ? 'bold' : 'semibold'} style={{ color: p.fg, fontSize }} numberOfLines={1}>
          {children}
        </Text>
      ) : (
        children
      )}
    </Pressable>
  );
}

// ------------------------------------------------------------------ Tarjetas

export function Card({ children, style, padded = true, title, right }: { children: ReactNode; style?: StyleProp<ViewStyle>; padded?: boolean; title?: string; right?: ReactNode }) {
  return (
    <View style={[styles.card, padded ? { padding: 16 } : null, style]}>
      {title ? (
        <View style={styles.cardHeader}>
          <Text weight="bold" style={{ fontSize: 14, color: colors.navy[900], flex: 1 }}>
            {title}
          </Text>
          {right}
        </View>
      ) : null}
      {children}
    </View>
  );
}

/** Encabezado azul marino de la app de campo (bg-navy-900 con el círculo de marca). */
export function Hero({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  const brand = useBrand();
  return (
    <View style={[styles.hero, shadow.lg, style]}>
      <View style={[styles.heroCircle, { backgroundColor: brand.primary + '4D' }]} />
      {children}
    </View>
  );
}

export function HeroStat({ value, label, valueColor }: { value: ReactNode; label: string; valueColor?: string }) {
  return (
    <View style={styles.heroStat}>
      <Text weight="bold" style={{ fontSize: 22, color: valueColor ?? colors.white }}>
        {value}
      </Text>
      <Text style={{ fontSize: 11, color: colors.navy[200] }}>{label}</Text>
    </View>
  );
}

/** <x-ui.stat> sólido (KPI) o soft. */
export function Stat({ label, value, sub, tone = 'brand', variant = 'solid', valueColor, style }: { label: string; value: ReactNode; sub?: ReactNode; tone?: Tone; variant?: 'solid' | 'soft'; valueColor?: string; style?: StyleProp<ViewStyle> }) {
  const solid = variant === 'solid';
  return (
    <View style={[solid ? [styles.statSolid, { backgroundColor: solidTones[tone] }] : styles.card, { padding: 12, flex: 1 }, style]}>
      <Text weight="medium" style={{ fontSize: 11, color: solid ? 'rgba(255,255,255,0.85)' : colors.slate[500] }} numberOfLines={1}>
        {label}
      </Text>
      <Text weight="bold" style={{ fontSize: 20, color: valueColor ?? (solid ? colors.white : colors.navy[900]) }} numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </Text>
      {sub ? (
        <Text style={{ fontSize: 11, color: solid ? 'rgba(255,255,255,0.8)' : colors.slate[500] }} numberOfLines={1}>
          {sub}
        </Text>
      ) : null}
    </View>
  );
}

export function Progress({ value, color = colors.success[500], track = 'rgba(255,255,255,0.15)', height = 8 }: { value: number; color?: string; track?: string; height?: number }) {
  return (
    <View style={{ height, borderRadius: height, backgroundColor: track, overflow: 'hidden' }}>
      <View style={{ height, width: `${Math.max(0, Math.min(100, value))}%`, backgroundColor: color, borderRadius: height }} />
    </View>
  );
}

// ------------------------------------------------------------------ Formularios

export function Label({ children }: { children: ReactNode }) {
  return (
    <Text weight="semibold" style={styles.label}>
      {children}
    </Text>
  );
}

export function FieldError({ message }: { message?: string | null }) {
  if (!message) return null;
  return (
    <Text weight="medium" style={{ color: colors.danger[600], fontSize: 12, marginTop: 4 }}>
      {message}
    </Text>
  );
}

export function Input({ label, error, prefix, style, inputStyle, multiline, ...props }: TextInputProps & { label?: string; error?: string | null; prefix?: string; inputStyle?: StyleProp<TextStyle> }) {
  return (
    <View style={style as StyleProp<ViewStyle>}>
      {label ? <Label>{label}</Label> : null}
      <View style={[styles.inputWrap, error ? { borderColor: colors.danger[500] } : null, multiline ? { alignItems: 'flex-start' } : null]}>
        {prefix ? <Text style={{ color: colors.slate[500], marginRight: 4, fontSize: 15 }}>{prefix}</Text> : null}
        <TextInput
          placeholderTextColor={colors.slate[400]}
          multiline={multiline}
          {...props}
          style={[styles.input, multiline ? { minHeight: 72, textAlignVertical: 'top', paddingTop: 10 } : null, inputStyle]}
        />
      </View>
      <FieldError message={error} />
    </View>
  );
}

/** Opciones en rejilla (radio con estilo de tarjeta, como los formularios de cobro/visita de la web). */
export function OptionGrid<T extends string>({ options, value, onChange, columns = 2 }: { options: { value: T; label: string; icon?: IconType }[]; value: T; onChange: (v: T) => void; columns?: number }) {
  const brand = useBrand();
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', marginHorizontal: -4 }}>
      {options.map((o) => {
        const active = o.value === value;
        const Icon = o.icon;
        return (
          <View key={o.value} style={{ width: `${100 / columns}%`, padding: 4 }}>
            <Pressable
              onPress={() => onChange(o.value)}
              style={[styles.option, active ? { borderColor: brand.primary, backgroundColor: colors.brand[50] } : null]}
              accessibilityRole="radio"
              accessibilityState={{ selected: active }}
            >
              {Icon ? <Icon size={16} color={active ? colors.brand[700] : colors.slate[600]} /> : null}
              <Text weight="semibold" style={{ color: active ? colors.brand[700] : colors.slate[600], fontSize: 13, flexShrink: 1 }}>
                {o.label}
              </Text>
            </Pressable>
          </View>
        );
      })}
    </View>
  );
}

/** Pestañas tipo segmento (bg-slate-200/70 con pestaña blanca activa). */
export function Segmented<T extends string>({ options, value, onChange }: { options: { value: T; label: string; count?: number }[]; value: T; onChange: (v: T) => void }) {
  return (
    <View style={styles.segment}>
      {options.map((o) => {
        const active = o.value === value;
        return (
          <Pressable key={o.value} onPress={() => onChange(o.value)} style={[styles.segmentItem, active ? [styles.segmentActive, shadow.card] : null]}>
            <Text weight="semibold" style={{ color: active ? colors.navy[900] : colors.slate[600], fontSize: 13 }}>
              {o.label}
              {o.count !== undefined ? <Text style={{ color: colors.slate[400], fontSize: 13 }}> {o.count}</Text> : null}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

// ------------------------------------------------------------------ Otros

export function Avatar({ name, size = 40, color }: { name?: string | null; size?: number; color?: string }) {
  const brand = useBrand();
  return (
    <View style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: color ?? brand.primary, alignItems: 'center', justifyContent: 'center' }}>
      <Text weight="bold" style={{ color: colors.white, fontSize: size * 0.36 }}>
        {initials(name)}
      </Text>
    </View>
  );
}

export function Empty({ icon: Icon, title, description, action }: { icon?: IconType; title: string; description?: string; action?: ReactNode }) {
  return (
    <View style={styles.empty}>
      {Icon ? (
        <View style={styles.emptyIcon}>
          <Icon size={28} color={colors.slate[400]} />
        </View>
      ) : null}
      <Text weight="semibold" style={{ fontSize: 15, color: colors.slate[700], textAlign: 'center' }}>
        {title}
      </Text>
      {description ? <Text style={{ fontSize: 13, color: colors.slate[500], textAlign: 'center', marginTop: 4 }}>{description}</Text> : null}
      {action ? <View style={{ marginTop: 14 }}>{action}</View> : null}
    </View>
  );
}

export function Loading({ label = 'Cargando…' }: { label?: string }) {
  const brand = useBrand();
  return (
    <View style={{ padding: 40, alignItems: 'center', gap: 10 }}>
      <ActivityIndicator color={brand.primary} />
      <Text style={{ color: colors.slate[500], fontSize: 13 }}>{label}</Text>
    </View>
  );
}

export function ErrorBox({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={[styles.card, { padding: 16, borderColor: colors.danger[100], backgroundColor: colors.danger[50], gap: 10 }]}>
      <Text weight="semibold" style={{ color: colors.danger[700] }}>
        {message}
      </Text>
      {onRetry ? (
        <Button variant="secondary" size="sm" onPress={onRetry} style={{ alignSelf: 'flex-start' }}>
          Reintentar
        </Button>
      ) : null}
    </View>
  );
}

/** Fila etiqueta / valor (<x-ui.detail>). */
export function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 12, paddingVertical: 6 }}>
      <Text style={{ color: colors.slate[500], fontSize: 13 }}>{label}</Text>
      <View style={{ flexShrink: 1, alignItems: 'flex-end' }}>{typeof children === 'string' || typeof children === 'number' ? <Text weight="medium" style={{ fontSize: 13, textAlign: 'right' }}>{children}</Text> : children}</View>
    </View>
  );
}

/** Hoja inferior (<x-ui.modal>) con título y pie de acciones. */
export function Sheet({ visible, onClose, title, children, footer }: { visible: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode }) {
  const insets = useSafeAreaInsets();
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose} statusBarTranslucent>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <Pressable style={styles.backdrop} onPress={onClose} />
        <View style={[styles.sheet, { paddingBottom: Math.max(insets.bottom, 12) }]}>
          <View style={styles.sheetHandle} />
          <View style={styles.sheetHeader}>
            <Text weight="bold" style={{ fontSize: 17, color: colors.navy[900], flex: 1 }}>
              {title}
            </Text>
            <Pressable onPress={onClose} hitSlop={12}>
              <Text weight="semibold" style={{ color: colors.slate[500] }}>
                Cerrar
              </Text>
            </Pressable>
          </View>
          <ScrollView contentContainerStyle={{ padding: 16, gap: 14 }} keyboardShouldPersistTaps="handled">
            {children}
          </ScrollView>
          {footer ? <View style={styles.sheetFooter}>{footer}</View> : null}
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

export function Row({ children, gap = 8, style }: { children: ReactNode; gap?: number; style?: StyleProp<ViewStyle> }) {
  return <View style={[{ flexDirection: 'row', alignItems: 'center', gap }, style]}>{children}</View>;
}

const styles = StyleSheet.create({
  badge: { flexDirection: 'row', alignItems: 'center', gap: 5, borderRadius: radius.full, borderWidth: 1, paddingHorizontal: 8, paddingVertical: 2, alignSelf: 'flex-start' },
  badgeXs: { paddingHorizontal: 6, paddingVertical: 1 },
  badgeDot: { width: 6, height: 6, borderRadius: 3 },
  btn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, borderRadius: radius.md },
  btnSm: { paddingHorizontal: 10, paddingVertical: 7, borderRadius: radius.sm },
  btnMd: { paddingHorizontal: 14, paddingVertical: 11 },
  btnLg: { paddingHorizontal: 16, paddingVertical: 14 },
  card: { backgroundColor: colors.white, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.slate[200], ...shadow.card },
  cardHeader: { flexDirection: 'row', alignItems: 'center', marginBottom: 12, gap: 8 },
  hero: { backgroundColor: colors.navy[900], borderRadius: radius.xl, padding: 20, overflow: 'hidden' },
  heroCircle: { position: 'absolute', top: -40, right: -40, width: 160, height: 160, borderRadius: 80 },
  heroStat: { flex: 1, backgroundColor: 'rgba(255,255,255,0.10)', borderRadius: radius.md, paddingVertical: 10, alignItems: 'center' },
  statSolid: { borderRadius: radius.md, ...shadow.card },
  label: { fontSize: 12, color: colors.slate[600], marginBottom: 4 },
  inputWrap: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderColor: colors.slate[300], borderRadius: radius.sm, backgroundColor: colors.white, paddingHorizontal: 12 },
  input: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.slate[800], paddingVertical: Platform.OS === 'ios' ? 12 : 9 },
  option: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, borderWidth: 1, borderColor: colors.slate[300], borderRadius: radius.sm, paddingVertical: 11, paddingHorizontal: 8, backgroundColor: colors.white },
  segment: { flexDirection: 'row', backgroundColor: 'rgba(226,232,240,0.7)', borderRadius: radius.md, padding: 4 },
  segmentItem: { flex: 1, alignItems: 'center', paddingVertical: 8, borderRadius: radius.sm },
  segmentActive: { backgroundColor: colors.white },
  empty: { alignItems: 'center', paddingVertical: 36, paddingHorizontal: 24 },
  emptyIcon: { width: 56, height: 56, borderRadius: 28, backgroundColor: colors.slate[100], alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  backdrop: { flex: 1, backgroundColor: 'rgba(7,19,48,0.6)' },
  sheet: { backgroundColor: colors.white, borderTopLeftRadius: 22, borderTopRightRadius: 22, maxHeight: '92%' },
  sheetHandle: { alignSelf: 'center', width: 40, height: 4, borderRadius: 2, backgroundColor: colors.slate[300], marginTop: 8 },
  sheetHeader: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 16, paddingTop: 10, paddingBottom: 6 },
  sheetFooter: { flexDirection: 'row', gap: 10, paddingHorizontal: 16, paddingTop: 10, borderTopWidth: 1, borderTopColor: colors.slate[100] },
});
