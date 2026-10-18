import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Linking, Pressable, StyleSheet, View } from 'react-native';
import {
  BanknotesIcon,
  CheckBadgeIcon,
  ChevronDownIcon,
  ClipboardDocumentListIcon,
  HomeIcon,
  MagnifyingGlassIcon,
  MapIcon,
  MapPinIcon,
  PhoneIcon,
  TruckIcon,
} from 'react-native-heroicons/outline';
import { CheckCircleIcon } from 'react-native-heroicons/solid';

import { PaySheet, VisitSheet } from '@/components/CollectionSheets';
import { RequireAbility, Screen } from '@/components/Screen';
import { useToast } from '@/components/Toast';
import { Badge, Card, Empty, ErrorBox, Input, Loading, Row, Segmented, Stat, Status, Text } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { distance, firstName, longToday, money, shortDate, dateTime } from '@/lib/format';
import { currentPosition } from '@/lib/geo';
import type { Charge, CollectionClient, CollectionDay, Coords, Payment, Visit } from '@/lib/types';
import { colors, radius, shadow } from '@/theme';

/** «Mi cobranza» (secciones 26 y 27) — misma pantalla que /campo/cobranza en la web. */
export default function CobranzaScreen() {
  return (
    <RequireAbility ability="collection.field">
      <Cobranza />
    </RequireAbility>
  );
}

function Cobranza() {
  const { session } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [view, setView] = useState<'pendientes' | 'visitados'>('pendientes');
  const [search, setSearch] = useState('');
  const [term, setTerm] = useState('');
  const [here, setHere] = useState<Coords | null>(null);
  const [locating, setLocating] = useState(false);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [payClient, setPayClient] = useState<CollectionClient | null>(null);
  const [visitClient, setVisitClient] = useState<CollectionClient | null>(null);
  const [lastPayment, setLastPayment] = useState<Payment | null>(null);

  // Búsqueda con retardo (wire:model.live.debounce.300ms)
  useEffect(() => {
    const t = setTimeout(() => setTerm(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const locate = async () => {
    setLocating(true);
    const c = await currentPosition();
    setLocating(false);
    if (c) setHere(c);
    else toast('No se pudo obtener tu ubicación. Activa el GPS.', 'warning');
  };

  // Al abrir se ubica al cobrador para ordenar por cercanía (x-init="locate()")
  useEffect(() => {
    locate();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const q = useQuery({
    queryKey: ['collection-today', view, term, here?.lat, here?.lng],
    queryFn: () => api.get<CollectionDay>('/collection/today', { view, search: term, lat: here?.lat, lng: here?.lng }),
    placeholderData: keepPreviousData,
  });
  const d = q.data;

  const afterSave = (message: string) => {
    toast(message);
    qc.invalidateQueries({ queryKey: ['collection-today'] });
    qc.invalidateQueries({ queryKey: ['cash-cut'] });
    qc.invalidateQueries({ queryKey: ['collection-client'] });
  };

  return (
    <Screen tab onRefresh={() => q.refetch()}>
      <Row style={{ justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <View style={{ flex: 1 }}>
          <Text weight="medium" style={{ fontSize: 12, color: colors.slate[500] }}>
            {longToday()}
          </Text>
          <Text weight="bold" style={{ fontSize: 24, color: colors.navy[900] }}>
            Mi cobranza
          </Text>
          <Text style={{ fontSize: 13.5, color: colors.slate[500] }}>
            Hola {firstName(session?.user.name)} · {d?.summary.pending ?? '…'} clientes por visitar
          </Text>
        </View>
        <Pressable onPress={locate} style={[styles.locate, shadow.card]}>
          <MapPinIcon size={16} color={colors.brand[600]} />
          <Text weight="semibold" style={{ fontSize: 12, color: colors.slate[700] }}>
            {locating ? 'Ubicando…' : here ? 'Reordenar' : 'Ubicarme'}
          </Text>
        </Pressable>
      </Row>

      {/* Resumen del día */}
      <View style={{ gap: 10 }}>
        <Row gap={10}>
          <Stat tone="success" label="Cobrado hoy" value={money(d?.summary.collected ?? 0, false)} sub={`${d?.summary.payments ?? 0} ${d?.summary.payments === 1 ? 'pago' : 'pagos'}`} />
          <Pressable style={{ flex: 1 }} onPress={() => router.push('/(tabs)/corte')}>
            <Stat
              tone="purple"
              label="Mi corte abierto"
              value={money(d?.cash_cut?.expected_amount ?? 0, false)}
              sub={d?.cash_cut ? `${d.cash_cut.payments_count} cobros · ${d.cash_cut.folio}` : 'Sin efectivo por entregar'}
            />
          </Pressable>
        </Row>
        <Row gap={10}>
          <Stat variant="soft" label="Visitas hoy" value={String(d?.summary.visits ?? 0)} />
          <Stat variant="soft" label="Por cobrar" value={money(d?.summary.pending_amount ?? 0, false)} valueColor={colors.danger[600]} />
        </Row>
      </View>

      {lastPayment ? (
        <View style={styles.paid}>
          <CheckCircleIcon size={28} color={colors.success[600]} />
          <View style={{ flex: 1 }}>
            <Text weight="semibold" style={{ color: colors.success[700] }}>
              Pago {lastPayment.folio} registrado
            </Text>
            <Text style={{ fontSize: 12, color: colors.slate[600] }}>{money(lastPayment.amount)} · se envió confirmación al cliente</Text>
          </View>
          {lastPayment.receipt_url ? (
            <Pressable onPress={() => Linking.openURL(lastPayment.receipt_url!)} style={[styles.receiptBtn, shadow.card]}>
              <Text weight="semibold" style={{ fontSize: 12, color: colors.success[700] }}>
                Comprobante
              </Text>
            </Pressable>
          ) : null}
        </View>
      ) : null}

      <Segmented
        options={[
          { value: 'pendientes', label: 'Por visitar', count: d?.counts.pendientes },
          { value: 'visitados', label: 'Visitados hoy', count: d?.counts.visitados },
        ]}
        value={view}
        onChange={(v) => {
          setView(v);
          setExpanded(null);
        }}
      />
      <Input placeholder="Buscar cliente…" value={search} onChangeText={setSearch} autoCorrect={false} returnKeyType="search" />

      {d?.route && view === 'pendientes' ? (
        <View style={styles.routeBar}>
          <Row gap={6} style={{ flex: 1 }}>
            <TruckIcon size={16} color={colors.brand[700]} />
            <Text style={{ fontSize: 12, color: colors.brand[700], flex: 1 }}>
              Ruta del día: {d.route.stops_total} paradas ({d.route.stops_done} hechas)
            </Text>
          </Row>
          {d.route.maps_url ? (
            <Pressable onPress={() => Linking.openURL(d.route!.maps_url!)}>
              <Text weight="semibold" style={{ fontSize: 12, color: colors.brand[700], textDecorationLine: 'underline' }}>
                Abrir ruta
              </Text>
            </Pressable>
          ) : null}
        </View>
      ) : view === 'pendientes' && d?.ordered_by === 'distance' ? (
        <Row gap={4}>
          <MapPinIcon size={14} color={colors.slate[500]} />
          <Text style={{ fontSize: 12, color: colors.slate[500] }}>Ordenados por cercanía desde tu ubicación.</Text>
        </Row>
      ) : null}

      {q.isLoading ? <Loading label="Cargando tu cobranza…" /> : null}
      {q.error ? <ErrorBox message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}

      {d && !d.data.length ? (
        <Card padded={false}>
          <Empty
            icon={view === 'pendientes' ? CheckBadgeIcon : MagnifyingGlassIcon}
            title={view === 'pendientes' ? (term ? 'Sin coincidencias' : '¡Sin clientes pendientes!') : 'Aún no registras visitas hoy'}
            description={view === 'pendientes' ? 'No tienes clientes asignados con saldo por visitar.' : 'Los clientes visitados o cobrados hoy aparecerán aquí.'}
          />
        </Card>
      ) : null}

      {d?.data.map((c, i) => (
        <ClientCard
          key={c.id}
          c={c}
          number={view === 'pendientes' ? String(i + 1) : '✓'}
          expanded={expanded === c.id}
          onToggle={() => setExpanded(expanded === c.id ? null : c.id)}
          onPay={() => setPayClient(c)}
          onVisit={() => setVisitClient(c)}
        />
      ))}

      <PaySheet
        client={payClient}
        visible={!!payClient}
        onClose={() => setPayClient(null)}
        onSaved={(payment, message) => {
          setPayClient(null);
          setLastPayment(payment);
          afterSave(message);
        }}
      />
      <VisitSheet
        client={visitClient}
        visible={!!visitClient}
        onClose={() => setVisitClient(null)}
        onSaved={(message) => {
          setVisitClient(null);
          afterSave(message);
        }}
      />
    </Screen>
  );
}

function ClientCard({ c, number, expanded, onToggle, onPay, onVisit }: { c: CollectionClient; number: string; expanded: boolean; onToggle: () => void; onPay: () => void; onVisit: () => void }) {
  const phone = (c.phone || c.whatsapp || '').replace(/\D+/g, '');
  return (
    <View style={styles.card}>
      <View style={{ padding: 16 }}>
        <Row gap={12} style={{ alignItems: 'flex-start' }}>
          <View style={styles.number}>
            <Text weight="bold" style={{ color: colors.white, fontSize: 12 }}>
              {number}
            </Text>
          </View>
          <View style={{ flex: 1 }}>
            <Row style={{ justifyContent: 'space-between', alignItems: 'flex-start' }} gap={8}>
              <View style={{ flex: 1 }}>
                <Text weight="semibold" numberOfLines={1}>
                  {c.full_name}
                </Text>
                <Text style={{ fontSize: 12, color: colors.slate[500] }}>
                  #{c.code} · {c.plan?.name ?? 'Sin plan'}
                </Text>
              </View>
              <View style={{ alignItems: 'flex-end' }}>
                <Text weight="bold" style={{ fontSize: 18, color: c.balance > 0 ? colors.danger[600] : colors.success[700] }}>
                  {money(c.balance)}
                </Text>
                {c.overdue_days > 0 ? (
                  <Text weight="semibold" style={{ fontSize: 11, color: colors.danger[600] }}>
                    {c.overdue_days} {c.overdue_days === 1 ? 'día' : 'días'} de atraso
                  </Text>
                ) : null}
              </View>
            </Row>
            <Row gap={6} style={{ flexWrap: 'wrap', marginTop: 8 }}>
              <Status value={c.service_status} />
              {c.zone ? <Badge size="xs">{c.zone.name}</Badge> : null}
              {c.route_seq ? <Badge tone="brand" size="xs">Ruta #{c.route_seq}</Badge> : null}
              {c.distance_km !== null ? <Text style={{ fontSize: 11, color: colors.slate[400] }}>{distance(c.distance_km)}</Text> : null}
              {c.visit_today ? <Status value={c.visit_today.result} /> : null}
              {c.visit_today?.payment ? <Text style={{ fontSize: 11, color: colors.success[700] }}>{c.visit_today.payment.folio}</Text> : null}
            </Row>
            <Row gap={4} style={{ alignItems: 'flex-start', marginTop: 8 }}>
              <HomeIcon size={14} color={colors.slate[400]} style={{ marginTop: 1 }} />
              <Text style={{ fontSize: 12, color: colors.slate[600], flex: 1 }}>
                {c.address}
                {c.references ? <Text style={{ fontSize: 12, color: colors.slate[400] }}> · {c.references}</Text> : null}
              </Text>
            </Row>
          </View>
        </Row>
      </View>

      <View style={styles.actions}>
        <Action label="Cobrar" icon={BanknotesIcon} color={colors.success[700]} onPress={onPay} />
        <Action label="Visita" icon={ClipboardDocumentListIcon} color={colors.slate[700]} onPress={onVisit} />
        <Action label="Navegar" icon={MapIcon} color={colors.brand[700]} onPress={() => Linking.openURL(c.navigation_url)} />
        <Action label="Llamar" icon={PhoneIcon} color={phone ? colors.slate[700] : colors.slate[300]} onPress={phone ? () => Linking.openURL(`tel:${phone}`) : undefined} last />
      </View>

      <Pressable onPress={onToggle} style={styles.toggle}>
        <Text weight="semibold" style={{ fontSize: 11.5, color: colors.slate[500] }}>
          {expanded ? 'Ocultar historial' : 'Ver historial y cargos'}
        </Text>
        <ChevronDownIcon size={14} color={colors.slate[500]} style={{ transform: [{ rotate: expanded ? '180deg' : '0deg' }] }} />
      </Pressable>
      {expanded ? <History clientId={c.id} /> : null}
    </View>
  );
}

function Action({ label, icon: Icon, color, onPress, last }: { label: string; icon: typeof MapIcon; color: string; onPress?: () => void; last?: boolean }) {
  return (
    <Pressable onPress={onPress} disabled={!onPress} style={({ pressed }) => [styles.action, !last ? styles.actionDivider : null, pressed ? { backgroundColor: colors.slate[50] } : null]}>
      <Icon size={20} color={color} />
      <Text weight="semibold" style={{ fontSize: 12, color }}>
        {label}
      </Text>
    </Pressable>
  );
}

/** Cargos pendientes, últimos pagos y visitas anteriores (desplegable de la web). */
function History({ clientId }: { clientId: number }) {
  const q = useQuery({
    queryKey: ['collection-client', clientId],
    queryFn: () => api.get<{ charges: Charge[]; payments: Payment[]; visits: Visit[] }>(`/collection/clients/${clientId}`),
  });
  if (q.isLoading) return <Loading />;
  if (q.error || !q.data) return <View style={{ padding: 12 }}><ErrorBox message={errorMessage(q.error)} /></View>;
  const { charges, payments, visits } = q.data;

  return (
    <View style={styles.history}>
      <View>
        <Text weight="bold" style={styles.histTitle}>Cargos pendientes</Text>
        {charges.length ? (
          charges.map((ch) => (
            <Row key={ch.id} style={{ justifyContent: 'space-between', paddingVertical: 2 }} gap={8}>
              <Text style={{ fontSize: 12, color: colors.slate[600], flex: 1 }} numberOfLines={1}>
                {ch.description} <Text style={{ fontSize: 12, color: ch.overdue ? colors.danger[600] : colors.slate[400] }}>· {shortDate(ch.due_date)}</Text>
              </Text>
              <Text weight="bold" style={{ fontSize: 12 }}>{money(ch.balance)}</Text>
            </Row>
          ))
        ) : (
          <Text style={styles.histEmpty}>Sin cargos pendientes</Text>
        )}
      </View>
      <View>
        <Text weight="bold" style={styles.histTitle}>Últimos pagos</Text>
        {payments.length ? (
          payments.map((p) => (
            <Row key={p.id} style={{ justifyContent: 'space-between', paddingVertical: 2 }}>
              <Text style={{ fontSize: 12, color: colors.slate[600] }}>
                {shortDate(p.paid_at)} · {p.method_label}
              </Text>
              <Text weight="bold" style={{ fontSize: 12, color: colors.success[700] }}>{money(p.amount)}</Text>
            </Row>
          ))
        ) : (
          <Text style={styles.histEmpty}>Sin pagos</Text>
        )}
      </View>
      <View>
        <Text weight="bold" style={styles.histTitle}>Visitas anteriores</Text>
        {visits.length ? (
          visits.map((v) => (
            <Text key={v.id} style={{ fontSize: 12, color: colors.slate[600], paddingVertical: 2 }}>
              {dateTime(v.visited_at)} · <Text weight="bold" style={{ fontSize: 12 }}>{v.result.label}</Text>
              {v.promise_date ? ` (promesa ${shortDate(v.promise_date)})` : ''}
              {v.notes ? <Text style={{ fontSize: 12, color: colors.slate[400] }}> · {v.notes}</Text> : null}
            </Text>
          ))
        ) : (
          <Text style={styles.histEmpty}>Sin visitas registradas</Text>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  locate: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.slate[300], borderRadius: radius.sm, paddingHorizontal: 12, paddingVertical: 8 },
  paid: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderRadius: radius.md, borderWidth: 1, borderColor: colors.success[100], backgroundColor: colors.success[50] },
  receiptBtn: { backgroundColor: colors.white, borderRadius: radius.sm, paddingHorizontal: 12, paddingVertical: 8 },
  routeBar: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: colors.brand[50], borderRadius: radius.md, paddingHorizontal: 12, paddingVertical: 8 },
  card: { backgroundColor: colors.white, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.slate[200], overflow: 'hidden', ...shadow.card },
  number: { width: 36, height: 36, borderRadius: 18, backgroundColor: colors.navy[900], alignItems: 'center', justifyContent: 'center' },
  actions: { flexDirection: 'row', borderTopWidth: 1, borderTopColor: colors.slate[100] },
  action: { flex: 1, alignItems: 'center', gap: 4, paddingVertical: 12 },
  actionDivider: { borderRightWidth: 1, borderRightColor: colors.slate[100] },
  toggle: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 4, paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.slate[100] },
  history: { gap: 12, padding: 16, borderTopWidth: 1, borderTopColor: colors.slate[100], backgroundColor: 'rgba(248,250,252,0.7)' },
  histTitle: { fontSize: 12, color: colors.slate[700], marginBottom: 4 },
  histEmpty: { fontSize: 12, color: colors.slate[400] },
});
