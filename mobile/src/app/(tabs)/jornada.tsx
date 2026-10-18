import { useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, Linking, Pressable, StyleSheet, View } from 'react-native';
import {
  ArrowRightStartOnRectangleIcon,
  ChevronDownIcon,
  ClipboardDocumentCheckIcon,
  FaceSmileIcon,
  MapIcon,
  MapPinIcon,
  SignalIcon,
  SparklesIcon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from 'react-native-heroicons/outline';
import { CheckIcon, MapPinIcon as MapPinSolid, PhoneIcon } from 'react-native-heroicons/solid';

import { RouteMap } from '@/components/RouteMap';
import { RequireAbility, Screen } from '@/components/Screen';
import { useToast } from '@/components/Toast';
import { Badge, Button, Card, Empty, ErrorBox, Hero, HeroStat, Loading, Progress, Row, Status, Text } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { firstName, longToday, time } from '@/lib/format';
import { gpsParams } from '@/lib/geo';
import type { FieldDay, Stop } from '@/lib/types';
import { colors, radius, shadow, toTone } from '@/theme';

/** «Mi jornada» del técnico (secciones 23 y 25) — misma pantalla que /campo en la web. */
export default function JornadaScreen() {
  return (
    <RequireAbility ability="field.work">
      <Jornada />
    </RequireAbility>
  );
}

function Jornada() {
  const { session } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [busy, setBusy] = useState<string | null>(null);
  const [showMap, setShowMap] = useState(false);

  const day = useQuery({ queryKey: ['field-today'], queryFn: () => api.get<FieldDay>('/field/today', { type: 'tecnico' }) });
  const d = day.data;

  const run = async (key: string, fn: () => Promise<void>) => {
    setBusy(key);
    try {
      await fn();
    } catch (e) {
      toast(errorMessage(e), 'error');
    } finally {
      setBusy(null);
    }
  };

  const reload = () => qc.invalidateQueries({ queryKey: ['field-today'] });

  /** Las paradas sugeridas no existen aún: se inicia la jornada y se usa la parada guardada. */
  const savedStop = async (stop: Stop): Promise<Stop> => {
    if (stop.id) return stop;
    const res = await api.post<FieldDay & { message?: string }>('/field/start-day', { type: 'tecnico' });
    qc.setQueryData(['field-today'], res);
    const saved = res.stops.find((s) => s.key === stop.key);
    if (!saved?.id) throw new Error('No se pudo guardar la parada en tu ruta.');
    return saved;
  };

  const startDay = () =>
    run('start', async () => {
      const res = await api.post<FieldDay & { message?: string }>('/field/start-day', { type: 'tecnico' });
      qc.setQueryData(['field-today'], res);
      toast(res.message ?? 'Ruta del día lista');
    });

  const shareLocation = () =>
    run('location', async () => {
      const gps = await gpsParams();
      if (!gps.lat) {
        toast('No se pudo obtener el GPS. Activa la ubicación del teléfono.', 'warning');
        return;
      }
      await api.post('/field/location', gps);
      toast('Ubicación compartida con la oficina');
    });

  const onWay = (stop: Stop) =>
    run('onWay' + stop.key, async () => {
      await api.post(`/field/tickets/${stop.ref_id}/on-way`, await gpsParams());
      toast('Se avisó al cliente que vas en camino');
      reload();
    });

  const arrive = (stop: Stop) =>
    run('arrive' + stop.key, async () => {
      const gps = await gpsParams();
      const s = await savedStop(stop);
      await api.post(`/field/stops/${s.id}/arrive`, gps);
      toast('Llegada registrada' + (gps.lat ? ' con GPS' : ' (sin GPS)'));
      await reload();
    });

  const depart = (stop: Stop) =>
    run('depart' + stop.key, async () => {
      const gps = await gpsParams();
      const s = await savedStop(stop);
      await api.post(`/field/stops/${s.id}/depart`, gps);
      toast('Salida registrada');
      await reload();
      qc.invalidateQueries({ queryKey: ['tickets'] });
    });

  const skip = (stop: Stop) =>
    Alert.alert('Omitir parada', '¿Omitir esta parada?', [
      { text: 'Cancelar', style: 'cancel' },
      {
        text: 'Omitir',
        style: 'destructive',
        onPress: () =>
          run('skip' + stop.key, async () => {
            await api.post(`/field/stops/${stop.id}/skip`);
            toast('Parada omitida', 'warning');
            await reload();
          }),
      },
    ]);

  const open = (stop: Stop) => router.push(stop.type === 'installation_order' ? `/orden/${stop.ref_id}` : `/ticket/${stop.ref_id}`);

  const next = d?.stops.find((s) => !['done', 'skipped'].includes(s.status));

  return (
    <Screen tab onRefresh={() => day.refetch()}>
      <Hero>
        <Text style={{ color: colors.navy[200], fontSize: 14 }}>{longToday()}</Text>
        <Text weight="bold" style={{ color: colors.white, fontSize: 24, marginTop: 2 }}>
          Hola, {firstName(session?.user.name)}
        </Text>
        <Row gap={8} style={{ marginTop: 16 }}>
          <HeroStat value={d?.summary.stops ?? '–'} label="Paradas" />
          <HeroStat value={d?.summary.done ?? '–'} label="Atendidas" valueColor={colors.success[500]} />
          <HeroStat value={d ? d.summary.distance_km.toFixed(1) : '–'} label="km aprox." />
        </Row>
        <View style={{ marginTop: 12 }}>
          <Progress value={d?.summary.progress ?? 0} />
        </View>
        <Row gap={8} style={{ marginTop: 16 }}>
          {d?.maps_url ? (
            <Button variant="white" icon={MapIcon} onPress={() => Linking.openURL(d.maps_url!)} style={{ flex: 1 }}>
              Ruta completa
            </Button>
          ) : (
            <View style={[styles.heroGhost, { flex: 1 }]}>
              <MapIcon size={20} color={colors.navy[200]} />
              <Text style={{ color: colors.navy[200] }}>Sin ruta</Text>
            </View>
          )}
          <Pressable onPress={shareLocation} disabled={!!busy} style={({ pressed }) => [styles.heroGhost, { flex: 1, backgroundColor: 'rgba(255,255,255,0.15)' }, pressed ? { opacity: 0.85 } : null]}>
            <SignalIcon size={20} color={colors.white} />
            <Text weight="semibold" style={{ color: colors.white }}>
              {busy === 'location' ? 'Ubicando…' : 'Mi ubicación'}
            </Text>
          </Pressable>
        </Row>
      </Hero>

      {day.isLoading ? <Loading label="Cargando tu jornada…" /> : null}
      {day.error ? <ErrorBox message={errorMessage(day.error)} onRetry={() => day.refetch()} /> : null}

      {d?.suggested ? (
        <View style={styles.suggest}>
          <SparklesIcon size={24} color={colors.brand[600]} />
          <Text style={{ flex: 1, fontSize: 13.5, color: colors.slate[700] }}>
            <Text weight="bold" style={{ fontSize: 13.5 }}>
              Ruta sugerida
            </Text>{' '}
            con tus tickets e instalaciones, ordenada por urgencia y cercanía.
          </Text>
          <Button size="sm" onPress={startDay} loading={busy === 'start'}>
            Iniciar
          </Button>
        </View>
      ) : null}

      {d?.stops.length ? (
        <View>
          <Pressable onPress={() => setShowMap((v) => !v)} style={[styles.mapToggle, shadow.card]}>
            <Row gap={8}>
              <MapPinIcon size={20} color={colors.brand[600]} />
              <Text weight="semibold" style={{ color: colors.slate[700] }}>
                Ver mapa de la ruta
              </Text>
            </Row>
            <ChevronDownIcon size={20} color={colors.slate[400]} style={{ transform: [{ rotate: showMap ? '180deg' : '0deg' }] }} />
          </Pressable>
          {showMap ? (
            <View style={{ marginTop: 8 }}>
              <RouteMap stops={d.stops} start={d.start} />
            </View>
          ) : null}
        </View>
      ) : null}

      {d && !d.stops.length ? (
        <Card padded={false}>
          <Empty icon={FaceSmileIcon} title="Sin actividades para hoy" description={d.message ?? 'Tu supervisor puede asignarte una ruta.'} />
        </Card>
      ) : null}

      {d?.stops.map((stop, i) => (
        <StopCard
          key={stop.key}
          stop={stop}
          index={i}
          isNext={next?.key === stop.key}
          busy={busy}
          canSkip={!!d.route && stop.status === 'pending'}
          onWay={() => onWay(stop)}
          arrive={() => arrive(stop)}
          depart={() => depart(stop)}
          skip={() => skip(stop)}
          open={() => open(stop)}
        />
      ))}
    </Screen>
  );
}

function StopCard({
  stop,
  index,
  isNext,
  busy,
  canSkip,
  onWay,
  arrive,
  depart,
  skip,
  open,
}: {
  stop: Stop;
  index: number;
  isNext: boolean;
  busy: string | null;
  canSkip: boolean;
  onWay: () => void;
  arrive: () => void;
  depart: () => void;
  skip: () => void;
  open: () => void;
}) {
  const st = stop.status;
  const isTicket = stop.type === 'ticket';
  const isOrder = stop.type === 'installation_order';
  const closed = st === 'done' || st === 'skipped';
  const circle = st === 'done' ? colors.success[600] : st === 'arrived' ? colors.warning[500] : st === 'skipped' ? colors.slate[200] : colors.navy[900];

  return (
    <View style={[styles.stop, isNext ? styles.stopNext : null, closed ? { opacity: 0.7 } : null]}>
      {isNext ? (
        <View style={styles.nextBar}>
          <Text weight="bold" style={{ color: colors.white, fontSize: 11, letterSpacing: 0.6 }}>
            SIGUIENTE PARADA
          </Text>
        </View>
      ) : null}
      <View style={{ flexDirection: 'row', gap: 12, padding: 16 }}>
        <View style={[styles.circle, { backgroundColor: circle }]}>
          {st === 'done' ? (
            <CheckIcon size={20} color={colors.white} />
          ) : (
            <Text weight="bold" style={{ color: st === 'skipped' ? colors.slate[500] : colors.white }}>
              {stop.sequence ?? index + 1}
            </Text>
          )}
        </View>
        <View style={{ flex: 1, gap: 2 }}>
          <Row gap={6} style={{ flexWrap: 'wrap' }}>
            {stop.badge ? (
              <Badge tone={toTone(stop.badge.color)} size="xs">
                {stop.badge.label}
              </Badge>
            ) : null}
            {isTicket && (stop.sla === 'risk' || stop.sla === 'breached') ? (
              <Badge tone={stop.sla === 'breached' ? 'danger' : 'warning'} size="xs" dot>
                {stop.sla === 'breached' ? 'SLA vencido' : 'SLA por vencer'}
              </Badge>
            ) : null}
            {st !== 'pending' ? <Status value={stop.status_label} /> : null}
          </Row>
          <Text weight="bold" style={{ fontSize: 16, color: colors.navy[900], marginTop: 4 }}>
            {stop.title}
          </Text>
          {stop.subtitle ? <Text style={{ fontSize: 13.5, color: colors.slate[600] }}>{stop.subtitle}</Text> : null}
          {stop.address ? (
            <Row gap={4} style={{ alignItems: 'flex-start', marginTop: 2 }}>
              <MapPinSolid size={14} color={colors.slate[400]} style={{ marginTop: 1 }} />
              <Text style={{ fontSize: 12, color: colors.slate[500], flex: 1 }}>{stop.address}</Text>
            </Row>
          ) : null}
          {stop.arrived_at ? (
            <Text style={{ fontSize: 12, color: colors.slate[500], marginTop: 2 }}>
              Llegada {time(stop.arrived_at)}
              {stop.departed_at ? ` · salida ${time(stop.departed_at)}` : ''}
            </Text>
          ) : null}
        </View>
        {stop.phone ? (
          <Pressable onPress={() => Linking.openURL(`tel:${stop.phone}`)} style={styles.call} accessibilityLabel="Llamar">
            <PhoneIcon size={20} color={colors.success[600]} />
          </Pressable>
        ) : null}
      </View>

      {!closed ? (
        <>
          <View style={styles.actions}>
            {stop.navigation_url ? (
              <ActionButton label="Navegar" icon={MapIcon} variant="outline" onPress={() => Linking.openURL(stop.navigation_url!)} />
            ) : (
              <View style={[styles.action, styles.noCoords]}>
                <Text style={{ fontSize: 12, color: colors.slate[400] }}>Sin coordenadas</Text>
              </View>
            )}
            {st === 'pending' ? (
              <>
                {isTicket ? <ActionButton label={busy === 'onWay' + stop.key ? 'Avisando…' : 'En camino'} icon={TruckIcon} variant="soft" onPress={onWay} disabled={!!busy} /> : null}
                <ActionButton
                  label={busy === 'arrive' + stop.key ? 'Obteniendo GPS…' : 'Llegué'}
                  icon={MapPinIcon}
                  variant="primary"
                  onPress={arrive}
                  disabled={!!busy}
                  full={isTicket}
                />
              </>
            ) : (
              <>
                {isTicket ? <ActionButton label="Trabajar" icon={WrenchScrewdriverIcon} variant="primary" onPress={open} /> : null}
                {isOrder ? <ActionButton label="Ver orden" icon={ClipboardDocumentCheckIcon} variant="teal" onPress={open} /> : null}
                <ActionButton
                  label={busy === 'depart' + stop.key ? 'Registrando…' : 'Salí · terminar parada'}
                  icon={ArrowRightStartOnRectangleIcon}
                  variant="success"
                  onPress={depart}
                  disabled={!!busy}
                  full
                />
              </>
            )}
          </View>
          <View style={styles.footer}>
            {isTicket || isOrder ? (
              <Pressable onPress={open} hitSlop={8}>
                <Text weight="semibold" style={{ fontSize: 12.5, color: isOrder ? colors.teal[700] : colors.brand[600] }}>
                  {isOrder ? 'Abrir orden de instalación →' : 'Abrir ticket →'}
                </Text>
              </Pressable>
            ) : (
              <View />
            )}
            {canSkip ? (
              <Pressable onPress={skip} hitSlop={8}>
                <Text style={{ fontSize: 12.5, color: colors.slate[400] }}>Omitir</Text>
              </Pressable>
            ) : null}
          </View>
        </>
      ) : isTicket || isOrder ? (
        <View style={styles.footer}>
          <Pressable onPress={open} hitSlop={8}>
            <Text weight="semibold" style={{ fontSize: 12.5, color: colors.brand[600] }}>
              {isOrder ? 'Ver orden →' : 'Ver ticket →'}
            </Text>
          </Pressable>
        </View>
      ) : null}
    </View>
  );
}

function ActionButton({
  label,
  icon: Icon,
  variant,
  onPress,
  disabled,
  full,
}: {
  label: string;
  icon: typeof MapIcon;
  variant: 'primary' | 'outline' | 'soft' | 'success' | 'teal';
  onPress: () => void;
  disabled?: boolean;
  full?: boolean;
}) {
  const v = {
    primary: { bg: colors.brand[600], fg: colors.white, border: colors.brand[600] },
    outline: { bg: colors.white, fg: colors.slate[700], border: colors.slate[300] },
    soft: { bg: colors.brand[50], fg: colors.brand[700], border: colors.brand[200] },
    success: { bg: colors.success[600], fg: colors.white, border: colors.success[600] },
    teal: { bg: colors.teal[600], fg: colors.white, border: colors.teal[600] },
  }[variant];
  return (
    <Pressable
      onPress={disabled ? undefined : onPress}
      style={({ pressed }) => [
        styles.action,
        { backgroundColor: v.bg, borderColor: v.border, width: full ? '100%' : undefined, flexBasis: full ? '100%' : '47%' },
        disabled ? { opacity: 0.6 } : null,
        pressed ? { transform: [{ scale: 0.98 }] } : null,
      ]}
    >
      <Icon size={20} color={variant === 'outline' ? colors.brand[600] : v.fg} />
      <Text weight={variant === 'outline' || variant === 'soft' ? 'semibold' : 'bold'} style={{ color: v.fg, fontSize: 14 }}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  heroGhost: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, borderRadius: radius.md, paddingVertical: 11, backgroundColor: 'rgba(255,255,255,0.10)' },
  suggest: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: radius.md, borderWidth: 1, borderColor: colors.brand[200], backgroundColor: colors.brand[50] },
  mapToggle: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: colors.white, borderWidth: 1, borderColor: colors.slate[200], borderRadius: radius.md, paddingHorizontal: 16, paddingVertical: 12 },
  stop: { backgroundColor: colors.white, borderRadius: radius.xl, borderWidth: 1, borderColor: colors.slate[200], overflow: 'hidden', ...shadow.card },
  stopNext: { borderColor: colors.brand[300], borderWidth: 2 },
  nextBar: { backgroundColor: colors.brand[600], paddingHorizontal: 16, paddingVertical: 4 },
  circle: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center' },
  call: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.success[50], alignItems: 'center', justifyContent: 'center' },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, padding: 12, borderTopWidth: 1, borderTopColor: colors.slate[100], backgroundColor: 'rgba(248,250,252,0.6)' },
  action: { flexGrow: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, paddingVertical: 12, borderRadius: radius.md, borderWidth: 1 },
  noCoords: { borderStyle: 'dashed', borderColor: colors.slate[300], flexBasis: '47%' },
  footer: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingHorizontal: 16, paddingVertical: 9, borderTopWidth: 1, borderTopColor: colors.slate[100] },
});
