import { Tabs } from 'expo-router';
import { ComponentType } from 'react';
import {
  BanknotesIcon,
  CalculatorIcon,
  ClipboardDocumentCheckIcon,
  MapIcon,
  TicketIcon,
  UserCircleIcon,
  UsersIcon,
} from 'react-native-heroicons/outline';

import { useAuth } from '@/lib/auth';
import { useBrand } from '@/lib/brand';
import { TabName, visibleTabs } from '@/lib/tabs';
import { colors, fonts } from '@/theme';

const TABS: { name: TabName; title: string; icon: ComponentType<{ size?: number; color?: string }> }[] = [
  { name: 'jornada', title: 'Jornada', icon: MapIcon },
  { name: 'tickets', title: 'Tickets', icon: TicketIcon },
  { name: 'ordenes', title: 'Órdenes', icon: ClipboardDocumentCheckIcon },
  { name: 'cobranza', title: 'Cobranza', icon: BanknotesIcon },
  { name: 'corte', title: 'Corte', icon: CalculatorIcon },
  { name: 'clientes', title: 'Clientes', icon: UsersIcon },
  { name: 'perfil', title: 'Perfil', icon: UserCircleIcon },
];

export default function TabsLayout() {
  const { can } = useAuth();
  const brand = useBrand();
  const visible = visibleTabs(can);

  return (
    <Tabs
      initialRouteName={visible[0]}
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: brand.primary,
        tabBarInactiveTintColor: colors.slate[400],
        tabBarLabelStyle: { fontFamily: fonts.semibold, fontSize: 11 },
        tabBarStyle: { backgroundColor: colors.white, borderTopColor: colors.slate[200] },
        sceneStyle: { backgroundColor: colors.surface },
      }}
    >
      {TABS.map((t) => (
        <Tabs.Screen
          key={t.name}
          name={t.name}
          options={{
            title: t.title,
            href: visible.includes(t.name) ? undefined : null,
            tabBarIcon: ({ color, size }) => <t.icon color={String(color)} size={size} />,
          }}
        />
      ))}
    </Tabs>
  );
}
