import { useQuery, useQueryClient } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Linking, Pressable, StyleSheet, Switch, View } from 'react-native';
import { ArrowRightStartOnRectangleIcon, CheckIcon, MapIcon, MapPinIcon, PaperAirplaneIcon, TruckIcon } from 'react-native-heroicons/outline';
import { MapPinIcon as MapPinSolid, PhoneIcon } from 'react-native-heroicons/solid';

import { EvidenceSection } from '@/components/EvidenceSection';
import { MaterialsSection } from '@/components/MaterialsSection';
import { Screen } from '@/components/Screen';
import { SignatureSection } from '@/components/SignatureSection';
import { useToast } from '@/components/Toast';
import { Avatar, Badge, Button, Card, ErrorBox, Hero, Input, Loading, Row, Status, Text } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { shortDateTime, time } from '@/lib/format';
import { gpsParams } from '@/lib/geo';
import type { Ticket } from '@/lib/types';
import { colors, radius } from '@/theme';

/** Estados que puede elegir el técnico (igual que la app de campo web). */
const STATUS_OPTIONS = [
  { value: 'en_proceso', label: 'En proceso' },
  { value: 'en_espera', label: 'En espera' },
  { value: 'resuelto', label: 'Resuelto' },
] as const;

/**
 * Trabajo sobre un ticket (secciones 23 y 24): datos del cliente, en camino / llegada / salida
 * con GPS, estado, evidencias, materiales, comentarios y firma. Igual que /campo/ticket/{id}.
 */
export default function TicketWork() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { session, can } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [busy, setBusy] = useState<string | null>(null);
  const [resolving, setResolving] = useState(false);
  const [note, setNote] = useState('');
  const [noteError, setNoteError] = useState<string | undefined>();
  const [comment, setComment] = useState('');
  const [internal, setInternal] = useState(false);

  const key = ['ticket', id];
  const q = useQuery({ queryKey: key, queryFn: () => api.get<{ data: Ticket }>(`/tickets/${id}`).then((r) => r.data) });
  const t = q.data;

  const setTicket = (data: Ticket) => qc.setQueryData(key, data);
  const refresh = async () => {
    await qc.invalidateQueries({ queryKey: key });
    qc.invalidateQueries({ queryKey: ['tickets'] });
    qc.invalidateQueries({ queryKey: ['field-today'] });
  };

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

  if (q.isLoading) return <Loading label="Cargando ticket…" />;
  if (q.error || !t) return <Screen><ErrorBox message={errorMessage(q.error)} onRetry={() => q.refetch()} /></Screen>;

  const c = t.client_detail;
  const manage = can('tickets.manage');
  const workable = t.open && (manage || t.technician?.id === session?.user.id);
  const arrived = !!t.arrived_at && !t.departed_at;

  const gpsAction = (name: 'on-way' | 'arrive' | 'depart', message: string) =>
    run(name, async () => {
      const gps = await gpsParams();
      const res = await api.post<{ data: Ticket }>(`/field/tickets/${t.id}/${name}`, gps);
      toast(message + (name === 'arrive' && gps.lat ? ' con GPS' : ''));
      await refresh();
      if (name === 'depart') router.back();
      else if (res.data) qc.invalidateQueries({ queryKey: key });
    });

  const changeStatus = (status: string) =>
    run('status' + status, async () => {
      setNoteError(undefined);
      try {
        const res = await api.patch<{ data: Ticket }>(`/tickets/${t.id}/status`, { status, note: status === 'resuelto' ? note : undefined, ...(await gpsParams()) });
        toast('Estado actualizado a ' + res.data.status.label);
        setResolving(false);
        setNote('');
        await refresh();
      } catch (e) {
        if (e instanceof ApiError && e.status === 422 && e.field('note')) {
          setNoteError(e.field('note'));
          return;
        }
        throw e;
      }
    });

  const addComment = () =>
    run('comment', async () => {
      if (comment.trim().length < 2) {
        toast('Escribe el comentario.', 'warning');
        return;
      }
      await api.post(`/tickets/${t.id}/comments`, { body: comment.trim(), internal });
      setComment('');
      setInternal(false);
      toast('Comentario agregado');
      await refresh();
    });

  return (
    <>
      <Stack.Screen options={{ title: `Ticket ${t.folio}` }} />
      <Screen onRefresh={() => q.refetch()}>
        <Hero>
          <Row gap={6} style={{ flexWrap: 'wrap' }}>
            <View style={styles.folio}>
              <Text weight="bold" style={{ color: colors.white, fontSize: 12 }}>
                {t.folio}
              </Text>
            </View>
            <Status value={t.status} />
            <Status value={t.priority} dot={false} />
            {t.sla.state === 'breached' ? (
              <Badge tone="danger" size="xs" dot>SLA vencido</Badge>
            ) : t.sla.state === 'risk' ? (
              <Badge tone="warning" size="xs" dot>SLA por vencer</Badge>
            ) : null}
          </Row>
          <Text weight="bold" style={{ color: colors.white, fontSize: 20, marginTop: 8 }}>
            {t.subject}
          </Text>
          <Text style={{ color: colors.navy[200], fontSize: 13.5, marginTop: 2 }}>{t.category.label}</Text>
          {t.description ? (
            <View style={styles.description}>
              <Text style={{ color: colors.navy[50], fontSize: 13.5 }}>{t.description}</Text>
            </View>
          ) : null}
        </Hero>

        {/* Cliente / sitio */}
        <Card>
          {c ? (
            <>
              <Row gap={12} style={{ alignItems: 'flex-start' }}>
                <Avatar name={c.full_name} size={46} />
                <View style={{ flex: 1, gap: 2 }}>
                  <Text weight="bold" style={{ fontSize: 16, color: colors.navy[900] }}>
                    {c.full_name}
                  </Text>
                  <Text style={{ fontSize: 12, color: colors.slate[500] }}>
                    #{c.code}
                    {c.plan ? ` · ${c.plan.name}` : ''}
                  </Text>
                  <Status value={c.service_status} />
                </View>
                {c.phone ? (
                  <Pressable onPress={() => Linking.openURL(`tel:${c.phone}`)} style={styles.call} accessibilityLabel="Llamar">
                    <PhoneIcon size={20} color={colors.success[600]} />
                  </Pressable>
                ) : null}
              </Row>
              <Row gap={6} style={{ alignItems: 'flex-start', marginTop: 12 }}>
                <MapPinSolid size={16} color={colors.slate[400]} style={{ marginTop: 2 }} />
                <View style={{ flex: 1 }}>
                  <Text style={{ fontSize: 13.5, color: colors.slate[600] }}>
                    {c.address}
                    {c.colonia ? `, ${c.colonia}` : ''}
                  </Text>
                  {c.references ? <Text style={{ fontSize: 12, color: colors.slate[500] }}>Ref.: {c.references}</Text> : null}
                </View>
              </Row>
              <Row gap={8} style={{ marginTop: 12 }}>
                <Mini label="Repetidor" value={c.repeater?.code ?? '—'} />
                <Mini label="Señal" value={c.signal_dbm ? `${c.signal_dbm} dBm` : '—'} />
                <Mini label="Conexión" value={c.online ? 'En línea' : 'Sin conexión'} color={c.online ? colors.success[600] : colors.slate[500]} />
              </Row>
            </>
          ) : t.repeater ? (
            <View>
              <Text style={{ fontSize: 12, color: colors.slate[500] }}>Infraestructura</Text>
              <Text weight="bold" style={{ fontSize: 16, color: colors.navy[900] }}>
                {t.repeater.code} · {t.repeater.name}
              </Text>
            </View>
          ) : (
            <Text style={{ color: colors.slate[500] }}>Ticket sin cliente asociado.</Text>
          )}

          {t.open && workable ? (
            <View style={{ marginTop: 16, gap: 8 }}>
              <Row gap={8}>
                {t.navigation_url ? (
                  <Button variant="secondary" icon={MapIcon} onPress={() => Linking.openURL(t.navigation_url!)} style={{ flex: 1 }}>
                    Navegar
                  </Button>
                ) : (
                  <View style={[styles.noCoords, { flex: 1 }]}>
                    <Text style={{ fontSize: 12, color: colors.slate[400] }}>Sin coordenadas</Text>
                  </View>
                )}
                {!arrived && c && !t.on_way_at ? (
                  <Button variant="soft" icon={TruckIcon} onPress={() => gpsAction('on-way', 'Se notificó al cliente que vas en camino')} loading={busy === 'on-way'} style={{ flex: 1 }}>
                    En camino
                  </Button>
                ) : null}
              </Row>
              {!arrived ? (
                <Button size="lg" icon={MapPinIcon} onPress={() => gpsAction('arrive', 'Llegada registrada')} loading={busy === 'arrive'}>
                  {busy === 'arrive' ? 'Obteniendo GPS…' : 'Llegué'}
                </Button>
              ) : (
                <Button size="lg" variant="success" icon={ArrowRightStartOnRectangleIcon} onPress={() => gpsAction('depart', 'Salida registrada. Parada terminada.')} loading={busy === 'depart'}>
                  Salí · terminar
                </Button>
              )}
              {t.on_way_at || t.arrived_at ? (
                <Text style={{ fontSize: 12, color: colors.slate[500] }}>
                  {[t.on_way_at ? `En camino ${time(t.on_way_at)}` : null, t.arrived_at ? `Llegada ${time(t.arrived_at)}` : null, t.departed_at ? `salida ${time(t.departed_at)}` : null]
                    .filter(Boolean)
                    .join(' · ')}
                </Text>
              ) : null}
            </View>
          ) : null}
        </Card>

        {/* Estado */}
        {t.open && workable ? (
          <Card title="Estado del trabajo">
            <Row gap={8}>
              {STATUS_OPTIONS.map((o) => {
                const active = t.status.value === o.value;
                const isResolve = o.value === 'resuelto';
                return (
                  <Pressable
                    key={o.value}
                    onPress={() => (isResolve ? setResolving((v) => !v) : changeStatus(o.value))}
                    disabled={!!busy}
                    style={[
                      styles.statusBtn,
                      active ? { backgroundColor: isResolve ? colors.success[600] : colors.brand[600], borderColor: isResolve ? colors.success[600] : colors.brand[600] } : null,
                      !active && isResolve ? { borderColor: colors.success[100] } : null,
                    ]}
                  >
                    <Text weight="semibold" style={{ fontSize: 13.5, color: active ? colors.white : isResolve ? colors.success[700] : colors.slate[700] }}>
                      {busy === 'status' + o.value ? '…' : o.label}
                    </Text>
                  </Pressable>
                );
              })}
            </Row>
            {resolving ? (
              <View style={{ marginTop: 12, gap: 8 }}>
                <Input value={note} onChangeText={setNote} multiline maxLength={190} placeholder="Describe la solución aplicada (mínimo 5 caracteres)" error={noteError} />
                <Button variant="success" icon={CheckIcon} onPress={() => changeStatus('resuelto')} loading={busy === 'statusresuelto'}>
                  Marcar como resuelto
                </Button>
              </View>
            ) : null}
          </Card>
        ) : t.resolution ? (
          <Card title="Solución aplicada">
            <Text style={{ color: colors.slate[700] }}>{t.resolution}</Text>
          </Card>
        ) : null}

        <EvidenceSection
          attachments={t.attachments ?? []}
          editable={workable}
          canDelete={() => true}
          onUpload={async (file, kind, gps) => {
            await api.upload(`/tickets/${t.id}/attachments`, { file, kind, lat: gps?.lat, lng: gps?.lng, taken_at: new Date().toISOString() });
            await refresh();
          }}
          onDelete={async (attachmentId) => {
            await api.delete(`/tickets/${t.id}/attachments/${attachmentId}`);
            await refresh();
          }}
        />

        <MaterialsSection
          materials={t.materials ?? []}
          editable={workable}
          onAdd={async (data) => {
            await api.post(`/tickets/${t.id}/materials`, data);
            await refresh();
          }}
          onReturn={async (movementId) => {
            await api.post(`/tickets/${t.id}/materials/${movementId}/return`);
            await refresh();
          }}
        />

        {/* Comentarios */}
        <Card title="Comentarios e historial">
          {workable || t.open ? (
            <View style={{ gap: 8, marginBottom: 14 }}>
              <Input value={comment} onChangeText={setComment} multiline maxLength={2000} placeholder="Escribe un avance o comentario…" />
              <Row style={{ justifyContent: 'space-between' }}>
                <Row gap={6}>
                  <Switch value={internal} onValueChange={setInternal} trackColor={{ true: colors.brand[600], false: colors.slate[300] }} thumbColor={colors.white} />
                  <Text style={{ fontSize: 13, color: colors.slate[600] }}>Nota interna</Text>
                </Row>
                <Button size="sm" icon={PaperAirplaneIcon} onPress={addComment} loading={busy === 'comment'}>
                  Comentar
                </Button>
              </Row>
            </View>
          ) : null}
          <View style={{ gap: 12 }}>
            {(t.comments ?? []).slice(0, 30).map((cm) => (
              <View key={cm.id}>
                <Row gap={8} style={{ flexWrap: 'wrap' }}>
                  <Text weight="semibold" style={{ fontSize: 12, color: colors.slate[700] }}>
                    {cm.author}
                  </Text>
                  <Text style={{ fontSize: 12, color: colors.slate[500] }}>{shortDateTime(cm.created_at)}</Text>
                  {cm.internal ? <Badge tone="warning" size="xs">Interno</Badge> : null}
                </Row>
                <View style={cm.type === 'comment' ? styles.commentBubble : { marginTop: 2 }}>
                  <Text style={{ fontSize: 13.5, color: cm.type === 'comment' ? colors.slate[800] : colors.slate[600] }}>{cm.body}</Text>
                </View>
              </View>
            ))}
          </View>
        </Card>

        <SignatureSection
          signature={t.signature}
          defaultName={c?.full_name ?? ''}
          editable={workable}
          onSave={async (signedBy, dataUrl) => {
            const res = await api.post<{ data: Ticket }>(`/tickets/${t.id}/signature`, { signed_by: signedBy, signature: dataUrl });
            setTicket(res.data);
          }}
        />
      </Screen>
    </>
  );
}

function Mini({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <View style={styles.mini}>
      <Text style={{ fontSize: 11, color: colors.slate[500] }}>{label}</Text>
      <Text weight="semibold" style={{ fontSize: 12.5, color: color ?? colors.slate[800] }} numberOfLines={1}>
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  folio: { backgroundColor: 'rgba(255,255,255,0.15)', borderRadius: 6, paddingHorizontal: 8, paddingVertical: 2 },
  description: { marginTop: 12, backgroundColor: 'rgba(255,255,255,0.10)', borderRadius: radius.md, padding: 12 },
  call: { width: 40, height: 40, borderRadius: 20, backgroundColor: colors.success[50], alignItems: 'center', justifyContent: 'center' },
  mini: { flex: 1, backgroundColor: colors.slate[50], borderRadius: radius.sm, padding: 8, alignItems: 'center' },
  noCoords: { alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderStyle: 'dashed', borderColor: colors.slate[300], borderRadius: radius.md, paddingVertical: 11 },
  statusBtn: { flex: 1, alignItems: 'center', paddingVertical: 11, borderRadius: radius.md, borderWidth: 1, borderColor: colors.slate[200], backgroundColor: colors.white },
  commentBubble: { marginTop: 3, backgroundColor: colors.slate[50], borderRadius: radius.sm, padding: 10 },
});
