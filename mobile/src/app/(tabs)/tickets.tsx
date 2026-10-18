import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { CheckBadgeIcon, ChevronRightIcon } from 'react-native-heroicons/outline';
import { MapPinIcon } from 'react-native-heroicons/solid';

import { RequireAbility, Screen } from '@/components/Screen';
import { Badge, Card, Empty, ErrorBox, Loading, Row, Segmented, Status, Text } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { shortDateTime } from '@/lib/format';
import type { Paginated, Ticket } from '@/lib/types';
import { colors, radius, shadow } from '@/theme';

/** Mis tickets: abiertos (por prioridad y SLA) y resueltos. */
export default function TicketsScreen() {
  return (
    <RequireAbility ability="field.work">
      <Tickets />
    </RequireAbility>
  );
}

function Tickets() {
  const [view, setView] = useState<'open' | 'done'>('open');
  const q = useQuery({
    queryKey: ['tickets', view],
    queryFn: () =>
      api.get<Paginated<Ticket>>('/tickets', {
        mine: 1,
        status: view === 'open' ? 'open' : 'resuelto,cerrado',
        sort: view === 'open' ? 'sla_due_at' : '-updated_at',
        per_page: 50,
      }),
  });
  const list = q.data?.data ?? [];

  return (
    <Screen tab onRefresh={() => q.refetch()}>
      <View>
        <Text weight="bold" style={{ fontSize: 24, color: colors.navy[900] }}>
          Mis tickets
        </Text>
        <Text style={{ color: colors.slate[500], fontSize: 13.5 }}>Reportes de soporte asignados a ti.</Text>
      </View>
      <Segmented
        options={[
          { value: 'open', label: 'Abiertos' },
          { value: 'done', label: 'Resueltos' },
        ]}
        value={view}
        onChange={setView}
      />
      {q.isLoading ? <Loading /> : null}
      {q.error ? <ErrorBox message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {!q.isLoading && !list.length && !q.error ? (
        <Card padded={false}>
          <Empty icon={CheckBadgeIcon} title={view === 'open' ? '¡Sin tickets abiertos!' : 'Sin tickets resueltos'} description={view === 'open' ? 'Cuando te asignen un reporte aparecerá aquí.' : undefined} />
        </Card>
      ) : null}
      {list.map((t) => (
        <Pressable key={t.id} onPress={() => router.push(`/ticket/${t.id}`)} style={({ pressed }) => [styles.card, pressed ? { opacity: 0.9 } : null]}>
          <View style={{ flex: 1, gap: 4 }}>
            <Row gap={6} style={{ flexWrap: 'wrap' }}>
              <Text weight="bold" style={{ fontSize: 12, color: colors.slate[500] }}>
                {t.folio}
              </Text>
              <Status value={t.status} />
              <Status value={t.priority} dot={false} />
              {t.open && t.sla.state === 'breached' ? <Badge tone="danger" size="xs" dot>SLA vencido</Badge> : null}
              {t.open && t.sla.state === 'risk' ? <Badge tone="warning" size="xs" dot>SLA por vencer</Badge> : null}
            </Row>
            <Text weight="bold" style={{ fontSize: 15, color: colors.navy[900] }}>
              {t.subject}
            </Text>
            {t.client ? (
              <Text style={{ fontSize: 13, color: colors.slate[600] }}>
                {t.client.full_name} · #{t.client.code}
              </Text>
            ) : null}
            {t.client?.address ? (
              <Row gap={4} style={{ alignItems: 'flex-start' }}>
                <MapPinIcon size={13} color={colors.slate[400]} style={{ marginTop: 1 }} />
                <Text style={{ fontSize: 12, color: colors.slate[500], flex: 1 }} numberOfLines={1}>
                  {t.client.address}
                </Text>
              </Row>
            ) : null}
            <Text style={{ fontSize: 11.5, color: colors.slate[400] }}>
              {t.open && t.sla.due_at ? `SLA ${shortDateTime(t.sla.due_at)}` : `Actualizado ${shortDateTime(t.resolved_at ?? t.created_at)}`}
            </Text>
          </View>
          <ChevronRightIcon size={20} color={colors.slate[300]} />
        </Pressable>
      ))}
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: colors.white, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.slate[200], padding: 14, ...shadow.card },
});
