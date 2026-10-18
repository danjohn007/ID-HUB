import { createContext, ReactNode, useCallback, useContext, useRef, useState } from 'react';
import { Animated, Pressable, StyleSheet, View } from 'react-native';
import { CheckCircleIcon, ExclamationTriangleIcon, InformationCircleIcon, XCircleIcon } from 'react-native-heroicons/solid';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Text } from './ui';

import { colors, shadow } from '@/theme';

/** Avisos breves como $this->toast('...') del panel web: success | error | warning | info. */
type Kind = 'success' | 'error' | 'warning' | 'info';
type ToastFn = (message: string, kind?: Kind) => void;

const ToastContext = createContext<ToastFn>(() => undefined);

const kinds: Record<Kind, { icon: typeof CheckCircleIcon; color: string; bg: string }> = {
  success: { icon: CheckCircleIcon, color: colors.success[600], bg: colors.success[50] },
  error: { icon: XCircleIcon, color: colors.danger[600], bg: colors.danger[50] },
  warning: { icon: ExclamationTriangleIcon, color: colors.warning[600], bg: colors.warning[50] },
  info: { icon: InformationCircleIcon, color: colors.brand[600], bg: colors.brand[50] },
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();
  const [toast, setToast] = useState<{ message: string; kind: Kind } | null>(null);
  const anim = useRef(new Animated.Value(0)).current;
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const hide = useCallback(() => {
    Animated.timing(anim, { toValue: 0, duration: 180, useNativeDriver: true }).start(() => setToast(null));
  }, [anim]);

  const show = useCallback<ToastFn>(
    (message, kind = 'success') => {
      if (timer.current) clearTimeout(timer.current);
      setToast({ message, kind });
      Animated.spring(anim, { toValue: 1, useNativeDriver: true, friction: 8 }).start();
      timer.current = setTimeout(hide, kind === 'error' ? 5000 : 3200);
    },
    [anim, hide],
  );

  const k = toast ? kinds[toast.kind] : null;
  const Icon = k?.icon;

  return (
    <ToastContext.Provider value={show}>
      {children}
      {toast && k && Icon ? (
        <Animated.View
          pointerEvents="box-none"
          style={[
            styles.wrap,
            { top: insets.top + 8, opacity: anim, transform: [{ translateY: anim.interpolate({ inputRange: [0, 1], outputRange: [-20, 0] }) }] },
          ]}
        >
          <Pressable onPress={hide} style={[styles.toast, shadow.lg, { borderLeftColor: k.color }]}>
            <View style={[styles.icon, { backgroundColor: k.bg }]}>
              <Icon size={20} color={k.color} />
            </View>
            <Text weight="medium" style={{ flex: 1, fontSize: 14, color: colors.slate[800] }}>
              {toast.message}
            </Text>
          </Pressable>
        </Animated.View>
      ) : null}
    </ToastContext.Provider>
  );
}

export function useToast(): ToastFn {
  return useContext(ToastContext);
}

const styles = StyleSheet.create({
  wrap: { position: 'absolute', left: 12, right: 12, zIndex: 1000 },
  toast: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: colors.white, borderRadius: 14, padding: 12, borderLeftWidth: 4 },
  icon: { width: 32, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
});
