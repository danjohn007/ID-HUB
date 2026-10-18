import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { CalendarDaysIcon, CheckCircleIcon, CheckIcon, MapIcon, PlayIcon } from 'react-native-heroicons/outline';
import { CheckIcon as CheckSolid, MapPinIcon, PhoneIcon } from 'react-native-heroicons/solid';

import { EvidenceSection } from '@/components/EvidenceSection';
import { MaterialsSection } from '@/components/MaterialsSection';
import { Screen } from '@/components/Screen';
import { SignatureSection } from '@/components/SignatureSection';
import { useToast } from '@/components/Toast';
import { Button, Card, ErrorBox, Hero, Input, Loading, Progress, Row, Status, Text } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { dateTime, money } from '@/lib/format';
import { gpsParams } from '@/lib/geo';
import type { InstallationOrder } from '@/lib/types';
import { colors, radius } from '@/theme';

const STEPS = ['Prospecto', 'Cobertura', 'Cotización', 'Aceptación', 'Programada', 'Instalación', 'Activación', 'Cliente activo'];

/**
 * Orden de instalación en campo (secciones 23 y 40), como la vista web de la orden para el técnico:
 * iniciar → checklist, mediciones, fotografías, materiales, firma → finalizar instalación.
 */
export default function OrderScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const toast = useToast();
  const qc = useQueryClient();
  const key = ['installation', id];
  const q = useQuery({ queryKey: key, queryFn: () => api.get<{ data: InstallationOrder }>(`/installations/${id}`).then((r) => r.data) });
  const o = q.data;
  const [busy, setBusy] = useState<string | null>(null);
  const [measures, setMeasures] = useState({ signal_dbm: '', speed_down: '', speed_up: '' });
  const [measureErrors, setMeasureErrors] = useState<Record<string, string | undefined>>({});

  useEffect(() => {
    if (o) {
      setMeasures({
        signal_dbm: o.measures.signal_dbm?.toString() ?? '',
        speed_down: o.measures.speed_down?.toString() ?? '',
        speed_up: o.measures.speed_up?.toString() ?? '',
      });
    }
  }, [o?.id, o?.measures.signal_dbm, o?.measures.speed_down, o?.measures.speed_up]); // eslint-disable-line react-hooks/exhaustive-deps

  const setOrder = (data: InstallationOrder) => {
    qc.setQueryData(key, data);
    qc.invalidateQueries({ queryKey: ['installations'] });
    qc.invalidateQueries({ queryKey: ['field-today'] });
  };
  const reload = () => qc.invalidateQueries({ queryKey: key });

  const run = async (name: string, fn: () => Promise<void>) => {
    setBusy(name);
    try {
      await fn();
    } catch (e) {
      toast(errorMessage(e), 'error');
    } finally {
      setBusy(null);
    }
  };

  if (q.isLoading) return <Loading label="Cargando orden…" />;
  if (q.error || !o) return <Screen><ErrorBox message={errorMessage(q.error)} onRetry={() => q.refetch()} /></Screen>;

  const start = () =>
    run('start', async () => {
      const res = await api.post<{ data: InstallationOrder }>(`/installations/${o.id}/start`, await gpsParams());
      setOrder(res.data);
      toast('Instalación iniciada');
    });

  const toggle = (k: string, done: boolean) =>
    run('check' + k, async () => {
      const res = await api.post<{ data: InstallationOrder }>(`/installations/${o.id}/checklist`, { key: k, done });
      setOrder(res.data);
    });

  const saveMeasures = () =>
    run('measures', async () => {
      setMeasureErrors({});
      const num = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')));
      try {
        const res = await api.post<{ data: InstallationOrder }>(`/installations/${o.id}/measures`, {
          signal_dbm: num(measures.signal_dbm),
          speed_down: num(measures.speed_down),
          speed_up: num(measures.speed_up),
        });
        setOrder(res.data);
        toast('Mediciones guardadas');
      } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
          setMeasureErrors({ signal_dbm: e.field('signal_dbm'), speed_down: e.field('speed_down'), speed_up: e.field('speed_up') });
          return;
        }
        throw e;
      }
    });

  const complete = () =>
    Alert.alert('Finalizar instalación', '¿Finalizar la instalación? Quedará lista para activación en el panel.', [
      { text: 'Cancelar', style: 'cancel' },
      {
        text: 'Finalizar',
        onPress: () =>
          run('complete', async () => {
            const res = await api.post<{ data: InstallationOrder }>(`/installations/${o.id}/complete`, await gpsParams());
            setOrder(res.data);
            toast('Instalación finalizada. Lista para activación.');
          }),
      },
    ]);

  const editable = o.editable;

  return (
    <>
      <Stack.Screen options={{ title: `Orden ${o.folio}` }} />
      <Screen onRefresh={() => q.refetch()}>
        <Hero>
          <Row gap={6} style={{ flexWrap: 'wrap' }}>
            <View style={styles.folio}>
              <Text weight="bold" style={{ color: colors.white, fontSize: 12 }}>
                {o.folio}
              </Text>
            </View>
            <Status value={o.status} />
          </Row>
          <Text weight="bold" style={{ color: colors.white, fontSize: 20, marginTop: 8 }}>
            {o.customer_name}
          </Text>
          {o.plan ? (
            <Text style={{ color: colors.navy[200], fontSize: 13.5, marginTop: 2 }}>
              {o.plan.name} · {o.plan.speed} · {money(o.plan.price)}
            </Text>
          ) : null}
          {o.status.value === 'en_proceso' ? (
            <View style={{ marginTop: 14, gap: 6 }}>
              <Text style={{ color: colors.navy[200], fontSize: 12 }}>Checklist {o.checklist_progress}%</Text>
              <Progress value={o.checklist_progress} />
            </View>
          ) : null}
        </Hero>

        {/* Pasos del flujo (stepper de la web) */}
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 6, paddingVertical: 2 }}>
          {STEPS.map((label, i) => {
            const done = i < o.step || (i === o.step && o.status.value === 'activada');
            const current = i === o.step && !done;
            return (
              <View key={label} style={[styles.step, done ? styles.stepDone : current ? styles.stepCurrent : null]}>
                <View style={[styles.stepDot, { backgroundColor: done ? colors.success[600] : current ? colors.brand[600] : colors.slate[200] }]}>
                  {done ? <CheckSolid size={12} color={colors.white} /> : <Text weight="bold" style={{ fontSize: 10, color: current ? colors.white : colors.slate[500] }}>{i + 1}</Text>}
                </View>
                <Text weight={current ? 'bold' : 'medium'} style={{ fontSize: 11.5, color: done ? colors.success[700] : current ? colors.brand[700] : colors.slate[500] }}>
                  {label}
                </Text>
              </View>
            );
          })}
        </ScrollView>

        {/* Contacto y ubicación */}
        <Card>
          {o.address ? (
            <Row gap={6} style={{ alignItems: 'flex-start' }}>
              <MapPinIcon size={16} color={colors.slate[400]} style={{ marginTop: 2 }} />
              <View style={{ flex: 1 }}>
                <Text style={{ fontSize: 13.5, color: colors.slate[600] }}>{o.address}</Text>
                {o.references ? <Text style={{ fontSize: 12, color: colors.slate[500] }}>Ref.: {o.references}</Text> : null}
              </View>
            </Row>
          ) : null}
          {o.repeater ? <Text style={{ fontSize: 12.5, color: colors.slate[500], marginTop: 8 }}>Repetidor: <Text weight="semibold" style={{ fontSize: 12.5 }}>{o.repeater.code} · {o.repeater.name}</Text></Text> : null}
          <Row gap={8} style={{ marginTop: 12 }}>
            {o.navigation_url ? (
              <Button variant="secondary" icon={MapIcon} onPress={() => Linking.openURL(o.navigation_url!)} style={{ flex: 1 }}>
                Navegar
              </Button>
            ) : null}
            {o.phone ? (
              <Button variant="secondary" icon={PhoneIcon} onPress={() => Linking.openURL(`tel:${o.phone}`)} style={{ flex: 1 }}>
                Llamar
              </Button>
            ) : null}
          </Row>
        </Card>

        {/* Programación / iniciar */}
        {['aceptada', 'programada'].includes(o.status.value ?? '') ? (
          <Card title={o.status.value === 'aceptada' ? 'Programar instalación' : 'Instalación programada'}>
            {o.scheduled_at ? (
              <Row gap={8}>
                <CalendarDaysIcon size={20} color={colors.purple[600]} />
                <Text weight="semibold" style={{ color: colors.purple[700] }}>
                  {dateTime(o.scheduled_at)}
                </Text>
              </Row>
            ) : (
              <Text style={{ color: colors.slate[500], fontSize: 13 }}>La oficina aún no programa la fecha de instalación.</Text>
            )}
            {o.can_start ? (
              <Button size="lg" icon={PlayIcon} onPress={start} loading={busy === 'start'} style={{ marginTop: 14 }}>
                Iniciar instalación
              </Button>
            ) : null}
          </Card>
        ) : null}

        {['en_proceso', 'instalada', 'activada'].includes(o.status.value ?? '') ? (
          <>
            <Card title="Checklist de instalación">
              <View style={{ gap: 8 }}>
                {o.checklist.map((item) => (
                  <Pressable
                    key={item.key}
                    disabled={!editable || item.auto || !!busy}
                    onPress={() => toggle(item.key, !item.done)}
                    style={[styles.check, item.done ? styles.checkDone : null]}
                  >
                    <View style={[styles.checkBox, item.done ? { backgroundColor: colors.success[600], borderColor: colors.success[600] } : null]}>
                      {item.done ? <CheckIcon size={14} color={colors.white} /> : null}
                    </View>
                    <Text weight={item.done ? 'semibold' : 'regular'} style={{ flex: 1, color: item.done ? colors.success[700] : colors.slate[700] }}>
                      {item.label}
                    </Text>
                    {item.auto && !item.done ? <Text style={{ fontSize: 11, color: colors.slate[400] }}>automático</Text> : null}
                  </Pressable>
                ))}
              </View>
            </Card>

            <Card title="Mediciones">
              <Row gap={8} style={{ alignItems: 'flex-start' }}>
                <Input label="Señal (dBm)" value={measures.signal_dbm} onChangeText={(v) => setMeasures((m) => ({ ...m, signal_dbm: v }))} keyboardType="numbers-and-punctuation" placeholder="-58" editable={editable} error={measureErrors.signal_dbm} style={{ flex: 1 }} />
                <Input label="Bajada (Mbps)" value={measures.speed_down} onChangeText={(v) => setMeasures((m) => ({ ...m, speed_down: v }))} keyboardType="decimal-pad" placeholder="30" editable={editable} error={measureErrors.speed_down} style={{ flex: 1 }} />
                <Input label="Subida (Mbps)" value={measures.speed_up} onChangeText={(v) => setMeasures((m) => ({ ...m, speed_up: v }))} keyboardType="decimal-pad" placeholder="8" editable={editable} error={measureErrors.speed_up} style={{ flex: 1 }} />
              </Row>
              {editable ? (
                <Button variant="secondary" icon={CheckIcon} onPress={saveMeasures} loading={busy === 'measures'} style={{ marginTop: 12 }}>
                  Guardar mediciones
                </Button>
              ) : null}
            </Card>

            <EvidenceSection
              title="Fotografías"
              attachments={o.attachments ?? []}
              editable={editable}
              defaultKind="instalacion"
              onUpload={async (file, kind, gps) => {
                await api.upload(`/installations/${o.id}/attachments`, { file, kind, lat: gps?.lat, lng: gps?.lng });
                await reload();
              }}
              onDelete={async (attachmentId) => {
                await api.delete(`/installations/${o.id}/attachments/${attachmentId}`);
                await reload();
              }}
            />

            <MaterialsSection
              materials={o.materials ?? []}
              editable={editable}
              onAdd={async (data) => {
                await api.post(`/installations/${o.id}/materials`, data);
                await reload();
              }}
              onReturn={async (movementId) => {
                await api.post(`/installations/${o.id}/materials/${movementId}/return`);
                await reload();
              }}
            />

            <SignatureSection
              title="Firma del cliente"
              signature={o.signature}
              defaultName={o.customer_name}
              editable={editable}
              onSave={async (signedBy, dataUrl) => {
                const res = await api.post<{ data: InstallationOrder }>(`/installations/${o.id}/signature`, { signed_by: signedBy, signature: dataUrl });
                setOrder(res.data);
              }}
            />

            {editable ? (
              <Button size="lg" variant="success" icon={CheckCircleIcon} onPress={complete} loading={busy === 'complete'}>
                Finalizar instalación
              </Button>
            ) : null}
          </>
        ) : null}

        {o.status.value === 'instalada' ? (
          <View style={styles.notice}>
            <CheckCircleIcon size={24} color={colors.teal[700]} />
            <Text style={{ flex: 1, color: colors.teal[700], fontSize: 13.5 }}>
              Instalación terminada {dateTime(o.completed_at)}. La oficina activará el servicio del cliente.
            </Text>
          </View>
        ) : null}
      </Screen>
    </>
  );
}

const styles = StyleSheet.create({
  folio: { backgroundColor: 'rgba(255,255,255,0.15)', borderRadius: 6, paddingHorizontal: 8, paddingVertical: 2 },
  step: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 10, paddingVertical: 7, borderRadius: radius.full, borderWidth: 1, borderColor: colors.slate[200], backgroundColor: colors.white },
  stepDone: { borderColor: colors.success[100], backgroundColor: colors.success[50] },
  stepCurrent: { borderColor: colors.brand[200], backgroundColor: colors.brand[50] },
  stepDot: { width: 20, height: 20, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  check: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderRadius: radius.md, borderWidth: 1, borderColor: colors.slate[200], backgroundColor: colors.white },
  checkDone: { borderColor: colors.success[100], backgroundColor: colors.success[50] },
  checkBox: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: colors.slate[300], alignItems: 'center', justifyContent: 'center' },
  notice: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 14, borderRadius: radius.md, backgroundColor: colors.teal[50], borderWidth: 1, borderColor: colors.teal[100] },
});
