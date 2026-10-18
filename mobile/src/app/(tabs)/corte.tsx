import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { BanknotesIcon, CalculatorIcon, ClockIcon } from 'react-native-heroicons/outline';

import { RequireAbility, Screen } from '@/components/Screen';
import { useToast } from '@/components/Toast';
import { Button, Card, Detail, Empty, ErrorBox, Hero, Input, Loading, Row, Sheet, Status, Text } from '@/components/ui';
import { api, ApiError, errorMessage } from '@/lib/api';
import { dateTime, money, time } from '@/lib/format';
import type { CashCut, Paginated, Payment } from '@/lib/types';
import { colors, radius } from '@/theme';

/**
 * Control de efectivo (sección 27): el cobrador hace su corte de caja; el supervisor registra la
 * entrega y concilia en el panel (Cobranza → Cortes de caja), comparando lo cobrado contra lo entregado.
 */
export default function CorteScreen() {
  return (
    <RequireAbility ability="collection.field">
      <Corte />
    </RequireAbility>
  );
}

type CutResponse = { data: CashCut | null; payments: Payment[]; pending_delivery: CashCut[] };

function Corte() {
  const toast = useToast();
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [counted, setCounted] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | undefined>();

  const cut = useQuery({ queryKey: ['cash-cut'], queryFn: () => api.get<CutResponse>('/collection/cash-cut') });
  const history = useQuery({ queryKey: ['cash-cuts'], queryFn: () => api.get<Paginated<CashCut>>('/collection/cash-cuts', { per_page: 15 }) });
  const current = cut.data?.data;
  const payments = cut.data?.payments ?? [];
  const pending = cut.data?.pending_delivery ?? [];
  // Cortes ya cerrados que no están en "por entregar / conciliar" (es decir, conciliados)
  const past = (history.data?.data ?? []).filter((c) => c.status.value !== 'open' && !pending.some((p) => p.id === c.id));
  const countedNum = Number(counted.replace(',', '.'));
  const diff = counted.trim() !== '' && !isNaN(countedNum) && current ? Math.round((countedNum - current.expected_amount) * 100) / 100 : null;

  const close = async () => {
    setSaving(true);
    setError(undefined);
    try {
      const res = await api.post<{ message: string }>('/collection/cash-cut/close', {
        counted_amount: counted.trim() === '' ? undefined : countedNum,
        notes: notes.trim() || undefined,
      });
      toast(res.message);
      setOpen(false);
      setCounted('');
      setNotes('');
      qc.invalidateQueries({ queryKey: ['cash-cut'] });
      qc.invalidateQueries({ queryKey: ['cash-cuts'] });
      qc.invalidateQueries({ queryKey: ['collection-today'] });
    } catch (e) {
      if (e instanceof ApiError && e.status === 422 && e.field('counted_amount')) setError(e.field('counted_amount'));
      else toast(errorMessage(e), 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Screen tab onRefresh={() => Promise.all([cut.refetch(), history.refetch()])}>
      <View>
        <Text weight="bold" style={{ fontSize: 24, color: colors.navy[900] }}>
          Corte de caja
        </Text>
        <Text style={{ color: colors.slate[500], fontSize: 13.5 }}>Efectivo cobrado en campo por entregar al supervisor.</Text>
      </View>

      {cut.isLoading ? <Loading /> : null}
      {cut.error ? <ErrorBox message={errorMessage(cut.error)} onRetry={() => cut.refetch()} /> : null}

      {cut.data && !current ? (
        <Card padded={false}>
          <Empty icon={BanknotesIcon} title="Sin efectivo por entregar" description="Cuando registres cobros en efectivo se abrirá tu corte de caja automáticamente." />
        </Card>
      ) : null}

      {current ? (
        <>
          <Hero style={{ backgroundColor: colors.purple[700] }}>
            <Row style={{ justifyContent: 'space-between' }}>
              <Text weight="semibold" style={{ color: 'rgba(255,255,255,0.85)', fontSize: 13 }}>
                Mi corte abierto · {current.folio}
              </Text>
              <Status value={current.status} />
            </Row>
            <Text weight="bold" style={{ color: colors.white, fontSize: 34, marginTop: 6 }}>
              {money(current.expected_amount)}
            </Text>
            <Text style={{ color: 'rgba(255,255,255,0.85)', fontSize: 13 }}>
              {current.payments_count} {current.payments_count === 1 ? 'cobro en efectivo' : 'cobros en efectivo'} · desde {dateTime(current.opened_at)}
            </Text>
            <Button variant="white" icon={CalculatorIcon} onPress={() => { setCounted(current.expected_amount.toFixed(2)); setError(undefined); setOpen(true); }} style={{ marginTop: 16 }} disabled={!current.payments_count}>
              Hacer corte de caja
            </Button>
          </Hero>

          <Card title="Cobros del corte" padded>
            {payments.length ? (
              payments.map((p, i) => (
                <Row key={p.id} gap={10} style={[{ paddingVertical: 9 }, i > 0 ? { borderTopWidth: 1, borderTopColor: colors.slate[100] } : null]}>
                  <View style={{ flex: 1 }}>
                    <Text weight="medium" numberOfLines={1}>
                      {p.client?.full_name ?? `Cliente #${p.client_id}`}
                    </Text>
                    <Text style={{ fontSize: 12, color: colors.slate[500] }}>
                      {p.folio} · {time(p.paid_at)}
                    </Text>
                  </View>
                  <Text weight="bold" style={{ color: colors.success[700] }}>
                    {money(p.amount)}
                  </Text>
                </Row>
              ))
            ) : (
              <Text style={{ color: colors.slate[500], fontSize: 13 }}>Sin cobros en este corte.</Text>
            )}
          </Card>
        </>
      ) : null}

      {pending.length ? (
        <Card title="Por entregar / conciliar">
          {pending.map((c) => (
            <CutRow key={c.id} cut={c} />
          ))}
        </Card>
      ) : null}

      <Card title="Historial de cortes">
        {history.isLoading ? <Loading /> : null}
        {past.length ? (
          past.map((c) => <CutRow key={c.id} cut={c} />)
        ) : !history.isLoading ? (
          <Row gap={6}>
            <ClockIcon size={16} color={colors.slate[400]} />
            <Text style={{ color: colors.slate[500], fontSize: 13 }}>Aún no tienes cortes conciliados.</Text>
          </Row>
        ) : null}
      </Card>

      <Sheet
        visible={open}
        onClose={() => setOpen(false)}
        title="Hacer corte de caja"
        footer={
          <>
            <Button variant="secondary" onPress={() => setOpen(false)} style={{ flex: 1 }}>
              Cancelar
            </Button>
            <Button icon={CalculatorIcon} onPress={close} loading={saving} style={{ flex: 1.5 }}>
              Cerrar corte
            </Button>
          </>
        }
      >
        {current ? (
          <View style={styles.box}>
            <Detail label="Corte">{current.folio}</Detail>
            <Detail label="Cobros en efectivo">{String(current.payments_count)}</Detail>
            <Detail label="Efectivo esperado">
              <Text weight="bold" style={{ fontSize: 15, color: colors.navy[900] }}>{money(current.expected_amount)}</Text>
            </Detail>
          </View>
        ) : null}
        <Input label="Efectivo que entregas (contado)" prefix="$" value={counted} onChangeText={setCounted} keyboardType="decimal-pad" inputStyle={{ fontSize: 20, fontFamily: 'Inter_700Bold' }} error={error} />
        {diff !== null ? (
          <View style={[styles.diff, { backgroundColor: Math.abs(diff) < 0.01 ? colors.success[50] : colors.danger[50], borderColor: Math.abs(diff) < 0.01 ? colors.success[100] : colors.danger[100] }]}>
            <Text weight="semibold" style={{ color: Math.abs(diff) < 0.01 ? colors.success[700] : colors.danger[700] }}>
              {Math.abs(diff) < 0.01 ? 'Cuadra con lo cobrado' : `Diferencia ${money(diff)} (${diff < 0 ? 'faltante' : 'sobrante'})`}
            </Text>
          </View>
        ) : null}
        <Input label="Notas para el supervisor" value={notes} onChangeText={setNotes} multiline maxLength={300} placeholder="Opcional" />
        <Text style={{ fontSize: 12, color: colors.slate[500] }}>
          Al cerrar, el corte queda «por entregar». Entrega el efectivo a tu supervisor: él registra la entrega y concilia en el panel.
        </Text>
      </Sheet>
    </Screen>
  );
}

function CutRow({ cut }: { cut: CashCut }) {
  return (
    <View style={styles.cutRow}>
      <Row style={{ justifyContent: 'space-between' }}>
        <Text weight="semibold">{cut.folio}</Text>
        <Status value={cut.status} />
      </Row>
      <Text style={{ fontSize: 12, color: colors.slate[500], marginTop: 2 }}>
        {cut.payments_count} cobros · cerrado {dateTime(cut.closed_at)}
      </Text>
      <Row gap={14} style={{ marginTop: 6, flexWrap: 'wrap' }}>
        <Text style={{ fontSize: 12.5, color: colors.slate[600] }}>
          Esperado <Text weight="bold" style={{ fontSize: 12.5 }}>{money(cut.expected_amount)}</Text>
        </Text>
        {cut.delivered_amount !== null ? (
          <Text style={{ fontSize: 12.5, color: colors.slate[600] }}>
            Entregado <Text weight="bold" style={{ fontSize: 12.5 }}>{money(cut.delivered_amount)}</Text>
          </Text>
        ) : null}
        {cut.difference !== null && Math.abs(cut.difference) > 0.009 ? (
          <Text weight="semibold" style={{ fontSize: 12.5, color: colors.danger[600] }}>
            Dif. {money(cut.difference)}
          </Text>
        ) : null}
      </Row>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { backgroundColor: colors.slate[50], borderRadius: radius.md, paddingHorizontal: 12, paddingVertical: 6 },
  diff: { borderWidth: 1, borderRadius: radius.md, padding: 10 },
  cutRow: { paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: colors.slate[100] },
});
