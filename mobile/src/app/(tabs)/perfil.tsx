import Constants from 'expo-constants';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, Pressable, StyleSheet, View } from 'react-native';
import { ArrowRightOnRectangleIcon, BuildingOffice2Icon, CalculatorIcon, ChevronRightIcon, ServerIcon, SignalIcon, UsersIcon } from 'react-native-heroicons/outline';

import { Logo } from '@/components/Logo';
import { Screen } from '@/components/Screen';
import { useToast } from '@/components/Toast';
import { Avatar, Badge, Button, Card, Detail, Hero, Row, Text } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { dateTime } from '@/lib/format';
import { gpsParams } from '@/lib/geo';
import { TabName, visibleTabs } from '@/lib/tabs';
import { colors, radius } from '@/theme';

export default function Perfil() {
  const { session, server, logout, can, refresh } = useAuth();
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const user = session?.user;
  const tabs = visibleTabs(can);
  const hidden = (name: TabName, ability: string) => can(ability) && !tabs.includes(name);

  const shareLocation = async () => {
    setBusy(true);
    try {
      const gps = await gpsParams();
      if (!gps.lat) {
        toast('No se pudo obtener el GPS. Activa la ubicación del teléfono.', 'warning');
        return;
      }
      await api.post('/field/location', gps);
      await refresh();
      toast('Ubicación compartida con la oficina');
    } catch (e) {
      toast(errorMessage(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const confirmLogout = () =>
    Alert.alert('Cerrar sesión', '¿Cerrar sesión en este teléfono?', [
      { text: 'Cancelar', style: 'cancel' },
      { text: 'Cerrar sesión', style: 'destructive', onPress: () => logout() },
    ]);

  return (
    <Screen tab onRefresh={() => refresh()}>
      <Hero>
        <Row gap={14}>
          <Avatar name={user?.name} size={56} />
          <View style={{ flex: 1 }}>
            <Text weight="bold" style={{ color: colors.white, fontSize: 20 }}>
              {user?.name}
            </Text>
            <Text style={{ color: colors.navy[200], fontSize: 13 }}>{user?.email}</Text>
            <Badge tone="brand" size="xs" style={{ marginTop: 6 }}>
              {user?.role_label ?? ''}
            </Badge>
          </View>
        </Row>
      </Hero>

      <Card title="Mi empresa">
        <Row gap={10}>
          <BuildingOffice2Icon size={22} color={colors.brand[600]} />
          <Text weight="semibold" style={{ flex: 1 }}>
            {session?.tenant?.name ?? '—'}
          </Text>
        </Row>
        {user?.phone ? <Detail label="Teléfono">{user.phone}</Detail> : null}
        <Detail label="Última ubicación">{user?.last_location_at ? dateTime(user.last_location_at) : 'Sin registrar'}</Detail>
        <Button variant="secondary" icon={SignalIcon} onPress={shareLocation} loading={busy} style={{ marginTop: 10 }}>
          Compartir mi ubicación
        </Button>
      </Card>

      {hidden('clientes', 'clients.view') || hidden('corte', 'collection.field') ? (
        <Card padded={false}>
          {hidden('clientes', 'clients.view') ? <LinkRow icon={UsersIcon} label="Clientes" onPress={() => router.push('/(tabs)/clientes')} /> : null}
          {hidden('corte', 'collection.field') ? <LinkRow icon={CalculatorIcon} label="Corte de caja" onPress={() => router.push('/(tabs)/corte')} /> : null}
        </Card>
      ) : null}

      <Card>
        <Row gap={8}>
          <ServerIcon size={18} color={colors.slate[400]} />
          <Text style={{ fontSize: 12.5, color: colors.slate[500], flex: 1 }} numberOfLines={1}>
            {server.replace(/^https?:\/\//, '')}
          </Text>
          <Text style={{ fontSize: 12, color: colors.slate[400] }}>v{Constants.expoConfig?.version ?? '1.0.0'}</Text>
        </Row>
      </Card>

      <Button variant="danger" icon={ArrowRightOnRectangleIcon} onPress={confirmLogout}>
        Cerrar sesión
      </Button>

      <View style={{ alignItems: 'center', marginTop: 8 }}>
        <Logo dark={false} size="sm" />
      </View>
    </Screen>
  );
}

function LinkRow({ icon: Icon, label, onPress }: { icon: typeof UsersIcon; label: string; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.link, pressed ? { backgroundColor: colors.slate[50] } : null]}>
      <Icon size={20} color={colors.brand[600]} />
      <Text weight="semibold" style={{ flex: 1 }}>
        {label}
      </Text>
      <ChevronRightIcon size={18} color={colors.slate[300]} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  link: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: colors.slate[100], borderRadius: radius.lg },
});
