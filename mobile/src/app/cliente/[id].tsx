import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Linking, View } from 'react-native';
import { BanknotesIcon, ChatBubbleLeftRightIcon, ClipboardDocumentListIcon, MapIcon, PhoneIcon } from 'react-native-heroicons/outline';
import { MapPinIcon } from 'react-native-heroicons/solid';

import { PaySheet, VisitSheet } from '@/components/CollectionSheets';
import { Screen } from '@/components/Screen';
import { useToast } from '@/components/Toast';
import { Avatar, Button, Card, Detail, ErrorBox, Loading, Row, Status, Text } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { date, money } from '@/lib/format';
import type { Client } from '@/lib/types';
import { colors } from '@/theme';

/** Ficha del cliente para personal de campo: contacto, ubicación, servicio y saldo. */
export default function ClientDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { can, session } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [pay, setPay] = useState(false);
  const [visit, setVisit] = useState(false);
  const q = useQuery({ queryKey: ['client', id], queryFn: () => api.get<{ data: Client & { collector?: { id: number } | null } }>(`/clients/${id}`).then((r) => r.data) });
  const c = q.data;

  if (q.isLoading) return <Loading label="Cargando cliente…" />;
  if (q.error || !c) return <Screen><ErrorBox message={errorMessage(q.error)} onRetry={() => q.refetch()} /></Screen>;

  const wa = (c.whatsapp || c.phone || '').replace(/\D+/g, '');
  const isMyDebtor = can('collection.field') && (c.collector?.id === session?.user.id || can('billing.manage'));
  const saved = (message: string) => {
    toast(message);
    setPay(false);
    setVisit(false);
    q.refetch();
    qc.invalidateQueries({ queryKey: ['collection-today'] });
    qc.invalidateQueries({ queryKey: ['cash-cut'] });
  };

  return (
    <>
      <Stack.Screen options={{ title: `Cliente #${c.code}` }} />
      <Screen onRefresh={() => q.refetch()}>
        <Card>
          <Row gap={12} style={{ alignItems: 'flex-start' }}>
            <Avatar name={c.full_name} size={52} />
            <View style={{ flex: 1, gap: 3 }}>
              <Text weight="bold" style={{ fontSize: 18, color: colors.navy[900] }}>
                {c.full_name}
              </Text>
              <Text style={{ fontSize: 12.5, color: colors.slate[500] }}>
                #{c.code}
                {c.plan ? ` · ${c.plan.name} ${c.plan.speed_label ?? ''}` : ''}
              </Text>
              <Status value={c.service_status} />
            </View>
          </Row>
          <Row gap={6} style={{ alignItems: 'flex-start', marginTop: 14 }}>
            <MapPinIcon size={16} color={colors.slate[400]} style={{ marginTop: 2 }} />
            <View style={{ flex: 1 }}>
              <Text style={{ fontSize: 13.5, color: colors.slate[600] }}>
                {c.address}
                {c.colonia ? `, ${c.colonia}` : ''}
                {c.community ? `, ${c.community}` : ''}
              </Text>
              {c.references ? <Text style={{ fontSize: 12, color: colors.slate[500] }}>Ref.: {c.references}</Text> : null}
            </View>
          </Row>
          <Row gap={8} style={{ marginTop: 14 }}>
            {c.navigation_url ? (
              <Button variant="secondary" size="sm" icon={MapIcon} onPress={() => Linking.openURL(c.navigation_url!)} style={{ flex: 1 }}>
                Navegar
              </Button>
            ) : null}
            {c.phone ? (
              <Button variant="secondary" size="sm" icon={PhoneIcon} onPress={() => Linking.openURL(`tel:${c.phone}`)} style={{ flex: 1 }}>
                Llamar
              </Button>
            ) : null}
            {wa ? (
              <Button variant="secondary" size="sm" icon={ChatBubbleLeftRightIcon} onPress={() => Linking.openURL(`https://wa.me/${wa.length === 10 ? '52' + wa : wa}`)} style={{ flex: 1 }}>
                WhatsApp
              </Button>
            ) : null}
          </Row>
        </Card>

        <Card title="Saldo">
          <Detail label="Saldo pendiente">
            <Text weight="bold" style={{ fontSize: 15, color: c.balance > 0 ? colors.danger[600] : colors.success[700] }}>
              {money(c.balance)}
            </Text>
          </Detail>
          {c.credit_balance > 0 ? <Detail label="Saldo a favor">{money(c.credit_balance)}</Detail> : null}
          <Detail label="Próximo vencimiento">{date(c.next_due_date) || '—'}</Detail>
          {isMyDebtor ? (
            <Row gap={8} style={{ marginTop: 10 }}>
              <Button variant="success" icon={BanknotesIcon} onPress={() => setPay(true)} style={{ flex: 1 }}>
                Cobrar
              </Button>
              <Button variant="secondary" icon={ClipboardDocumentListIcon} onPress={() => setVisit(true)} style={{ flex: 1 }}>
                Visita
              </Button>
            </Row>
          ) : null}
        </Card>

        <Card title="Servicio">
          <Detail label="Plan">{c.plan ? `${c.plan.name} · ${c.plan.speed_label}` : '—'}</Detail>
          <Detail label="Repetidor">{c.repeater ? `${c.repeater.code} · ${c.repeater.name}` : '—'}</Detail>
          <Detail label="Zona">{c.zone?.name ?? '—'}</Detail>
          <Detail label="Conexión">
            <Text weight="semibold" style={{ fontSize: 13, color: c.online ? colors.success[600] : colors.slate[500] }}>
              {c.online ? 'En línea' : 'Sin conexión'}
            </Text>
          </Detail>
          <Detail label="Señal">{c.signal_dbm ? `${c.signal_dbm} dBm` : '—'}</Detail>
          <Detail label="IP">{c.ip_address ?? '—'}</Detail>
          <Detail label="Usuario PPPoE">{c.pppoe_user ?? '—'}</Detail>
          <Detail label="Instalación">{date(c.installation_date) || '—'}</Detail>
        </Card>

        {c.notes ? (
          <Card title="Notas">
            <Text style={{ color: colors.slate[700] }}>{c.notes}</Text>
          </Card>
        ) : null}

        <PaySheet client={c} visible={pay} onClose={() => setPay(false)} onSaved={(_, message) => saved(message)} />
        <VisitSheet client={c} visible={visit} onClose={() => setVisit(false)} onSaved={saved} />
      </Screen>
    </>
  );
}
