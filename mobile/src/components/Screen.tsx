import { Redirect } from 'expo-router';
import { ReactNode, useState } from 'react';
import { RefreshControl, ScrollView, StyleProp, View, ViewStyle } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/lib/auth';
import { useBrand } from '@/lib/brand';
import { visibleTabs } from '@/lib/tabs';
import { colors } from '@/theme';

/** Contenedor de pantalla: fondo surface, ancho cómodo, deslizar para actualizar. */
export function Screen({
  children,
  onRefresh,
  tab = false,
  contentStyle,
}: {
  children: ReactNode;
  onRefresh?: () => Promise<unknown>;
  tab?: boolean;
  contentStyle?: StyleProp<ViewStyle>;
}) {
  const brand = useBrand();
  const [refreshing, setRefreshing] = useState(false);
  const refresh = onRefresh
    ? async () => {
        setRefreshing(true);
        try {
          await onRefresh();
        } finally {
          setRefreshing(false);
        }
      }
    : undefined;

  const body = (
    <ScrollView
      style={{ flex: 1, backgroundColor: colors.surface }}
      contentContainerStyle={[{ padding: 16, paddingBottom: 32, gap: 14 }, contentStyle]}
      keyboardShouldPersistTaps="handled"
      refreshControl={refresh ? <RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={brand.primary} colors={[brand.primary]} /> : undefined}
    >
      {children}
    </ScrollView>
  );

  return tab ? (
    <SafeAreaView edges={['top']} style={{ flex: 1, backgroundColor: colors.surface }}>
      {body}
    </SafeAreaView>
  ) : (
    <View style={{ flex: 1 }}>{body}</View>
  );
}

/** Si el usuario no tiene la función, lo lleva a su primera pestaña. */
export function RequireAbility({ ability, children }: { ability: string | string[]; children: ReactNode }) {
  const { can } = useAuth();
  const list = Array.isArray(ability) ? ability : [ability];
  if (!can(...list)) return <Redirect href={`/(tabs)/${visibleTabs(can)[0]}`} />;
  return <>{children}</>;
}
