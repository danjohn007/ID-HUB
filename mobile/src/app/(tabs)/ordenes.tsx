import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { CalendarDaysIcon, ChevronRightIcon, ClipboardDocumentCheckIcon } from 'react-native-heroicons/outline';
import { MapPinIcon } from 'react-native-heroicons/solid';

import { RequireAbility, Screen } from '@/components/Screen';
import { Card, Empty, ErrorBox, Loading, Progress, Row, Segmented, Status, Text } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { dateTime } from '@/lib/format';
import type { InstallationOrder, Paginated } from '@/lib/types';
import { colors, radius, shadow } from '@/theme';

/** Órdenes de instalación asignadas al técnico (sección 40). */
export default function OrdenesScreen() {
  return (
    <RequireAbility ability="field.work">
      <Ordenes />
    </RequireAbility>
  );
}

function Ordenes() {
  const [scope, setScope] = useState<'open' | 'done'>('open');
  const q = useQuery({
    queryKey: ['installations', scope],
    queryFn: () => api.get<Paginated<InstallationOrder>>('/installations', { scope, per_page: 50 }),
  });
  const list = q.data?.data ?? [];

  return (
    <Screen tab onRefresh={() => q.refetch()}>
      <View>
        <Text weight="bold" style={{ fontSize: 24, color: colors.navy[900] }}>
          Órdenes de instalación
        </Text>
        <Text style={{ color: colors.slate[500], fontSize: 13.5 }}>Instalaciones asignadas a ti.</Text>
      </View>
      <Segmented
        options={[
          { value: 'open', label: 'Por instalar' },
          { value: 'done', label: 'Instaladas' },
        ]}
        value={scope}
        onChange={setScope}
      />
      {q.isLoading ? <Loading /> : null}
      {q.error ? <ErrorBox message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {!q.isLoading && !list.length && !q.error ? (
        <Card padded={false}>
          <Empty icon={ClipboardDocumentCheckIcon} title={scope === 'open' ? 'Sin instalaciones pendientes' : 'Sin instalaciones terminadas'} description={scope === 'open' ? 'Las órdenes programadas para ti aparecerán aquí.' : undefined} />
        </Card>
      ) : null}
      {list.map((o) => (
        <Pressable key={o.id} onPress={() => router.push(`/orden/${o.id}`)} style={({ pressed }) => [styles.card, pressed ? { opacity: 0.9 } : null]}>
          <View style={{ flex: 1, gap: 4 }}>
            <Row gap={6}>
              <Text weight="bold" style={{ fontSize: 12, color: colors.slate[500] }}>
                {o.folio}
              </Text>
              <Status value={o.status} />
            </Row>
            <Text weight="bold" style={{ fontSize: 15, color: colors.navy[900] }}>
              {o.customer_name}
            </Text>
            {o.plan ? <Text style={{ fontSize: 13, color: colors.slate[600] }}>{o.plan.name} · {o.plan.speed}</Text> : null}
            {o.address ? (
              <Row gap={4} style={{ alignItems: 'flex-start' }}>
                <MapPinIcon size={13} color={colors.slate[400]} style={{ marginTop: 1 }} />
                <Text style={{ fontSize: 12, color: colors.slate[500], flex: 1 }} numberOfLines={1}>
                  {o.address}
                </Text>
              </Row>
            ) : null}
            {o.scheduled_at ? (
              <Row gap={4}>
                <CalendarDaysIcon size={14} color={colors.purple[600]} />
                <Text weight="medium" style={{ fontSize: 12, color: colors.purple[700] }}>
                  {dateTime(o.scheduled_at)}
                </Text>
              </Row>
            ) : null}
            {o.status.value === 'en_proceso' ? (
              <View style={{ marginTop: 4, gap: 3 }}>
                <Text style={{ fontSize: 11.5, color: colors.slate[500] }}>Checklist {o.checklist_progress}%</Text>
                <Progress value={o.checklist_progress} track={colors.slate[100]} height={6} />
              </View>
            ) : null}
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
