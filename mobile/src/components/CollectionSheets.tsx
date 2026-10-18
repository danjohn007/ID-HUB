import DateTimePicker from '@react-native-community/datetimepicker';
import { Image } from 'expo-image';
import { useEffect, useState } from 'react';
import { Platform, Pressable, StyleSheet, View } from 'react-native';
import {
  BanknotesIcon,
  CalendarDaysIcon,
  CameraIcon,
  CheckIcon,
  HandRaisedIcon,
  LockClosedIcon,
  MapPinIcon,
  NoSymbolIcon,
  UserMinusIcon,
  XMarkIcon,
} from 'react-native-heroicons/outline';

import { useToast } from './Toast';
import { Button, FieldError, Input, Label, OptionGrid, Row, Sheet, Text } from './ui';

import { api, ApiError, errorMessage, UploadFile } from '@/lib/api';
import { date, isoDate, money } from '@/lib/format';
import { currentPosition } from '@/lib/geo';
import { choosePhoto } from '@/lib/photos';
import type { Coords, Payment } from '@/lib/types';
import { colors, radius } from '@/theme';

type ClientLite = { id: number; full_name: string; code: string; balance: number };

const METHODS = [
  { value: 'efectivo', label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'deposito', label: 'Depósito' },
  { value: 'tarjeta', label: 'Tarjeta' },
] as const;

const RESULTS = [
  { value: 'no_localizado', label: 'No localizado', icon: UserMinusIcon },
  { value: 'promesa', label: 'Promesa de pago', icon: HandRaisedIcon },
  { value: 'rechazo', label: 'Rechazo', icon: NoSymbolIcon },
  { value: 'cerrado', label: 'Domicilio cerrado', icon: LockClosedIcon },
] as const;

export type VisitResult = (typeof RESULTS)[number]['value'];

/** GPS que se toma al abrir el formulario (como locate-form en la web). */
function useFormGps(visible: boolean) {
  const [gps, setGps] = useState<Coords | null>(null);
  const [locating, setLocating] = useState(false);
  useEffect(() => {
    if (!visible) return;
    let alive = true;
    setLocating(true);
    currentPosition().then((c) => {
      if (alive) {
        setGps(c);
        setLocating(false);
      }
    });
    return () => {
      alive = false;
    };
  }, [visible]);
  return { gps, locating };
}

function ClientHeader({ client, highlight = true }: { client: ClientLite; highlight?: boolean }) {
  return (
    <View style={styles.clientBox}>
      <Text weight="semibold">{client.full_name}</Text>
      <Text style={{ fontSize: 12, color: colors.slate[500] }}>
        #{client.code} · Saldo{' '}
        <Text weight="bold" style={{ fontSize: 12, color: highlight ? colors.danger[600] : colors.slate[700] }}>
          {money(client.balance)}
        </Text>
      </Text>
    </View>
  );
}

/** Foto de evidencia opcional + indicador de GPS (partials/collection-evidence de la web). */
function EvidencePicker({ file, onChange, gps, locating, error }: { file: UploadFile | null; onChange: (f: UploadFile | null) => void; gps: Coords | null; locating: boolean; error?: string }) {
  return (
    <View style={{ gap: 6 }}>
      <Label>Foto de evidencia (opcional)</Label>
      {file ? (
        <Row gap={10}>
          <Image source={{ uri: file.uri }} style={styles.thumb} contentFit="cover" />
          <Pressable onPress={() => onChange(null)} hitSlop={8} style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
            <XMarkIcon size={16} color={colors.danger[600]} />
            <Text weight="semibold" style={{ color: colors.danger[600], fontSize: 13 }}>
              Quitar
            </Text>
          </Pressable>
        </Row>
      ) : (
        <Button variant="secondary" icon={CameraIcon} onPress={async () => onChange(await choosePhoto())} style={{ alignSelf: 'flex-start' }}>
          Agregar foto
        </Button>
      )}
      <FieldError message={error} />
      <Row gap={4}>
        <MapPinIcon size={14} color={gps ? colors.success[600] : colors.slate[400]} />
        <Text style={{ fontSize: 12, color: gps ? colors.success[700] : colors.slate[500] }}>
          {locating ? 'Obteniendo ubicación…' : gps ? 'Ubicación GPS registrada' : 'Sin GPS (se registrará sin ubicación)'}
        </Text>
      </Row>
    </View>
  );
}

export function PaySheet({ client, visible, onClose, onSaved }: { client: ClientLite | null; visible: boolean; onClose: () => void; onSaved: (payment: Payment, message: string) => void }) {
  const toast = useToast();
  const { gps, locating } = useFormGps(visible);
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState<(typeof METHODS)[number]['value']>('efectivo');
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');
  const [file, setFile] = useState<UploadFile | null>(null);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});

  useEffect(() => {
    if (visible && client) {
      setAmount(client.balance > 0 ? client.balance.toFixed(2) : '');
      setMethod('efectivo');
      setReference('');
      setNotes('');
      setFile(null);
      setErrors({});
    }
  }, [visible, client]);

  if (!client) return null;

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.upload<{ data: Payment; message: string }>('/collection/payments', {
        client_id: client.id,
        amount: amount.replace(',', '.'),
        method,
        reference: method !== 'efectivo' ? reference.trim() : undefined,
        notes: notes.trim(),
        lat: gps?.lat,
        lng: gps?.lng,
        evidence: file ?? undefined,
      });
      onSaved(res.data, res.message);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        setErrors({ amount: e.field('amount'), reference: e.field('reference'), notes: e.field('notes'), evidence: e.field('evidence') });
      } else {
        toast(errorMessage(e), 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Sheet
      visible={visible}
      onClose={onClose}
      title="Registrar pago"
      footer={
        <>
          <Button variant="secondary" onPress={onClose} style={{ flex: 1 }}>
            Cancelar
          </Button>
          <Button variant="success" icon={BanknotesIcon} onPress={save} loading={saving} style={{ flex: 1.5 }}>
            Registrar pago
          </Button>
        </>
      }
    >
      <ClientHeader client={client} />
      <Input label="Importe recibido" prefix="$" value={amount} onChangeText={setAmount} keyboardType="decimal-pad" inputStyle={{ fontSize: 20, fontFamily: 'Inter_700Bold' }} error={errors.amount} />
      <View>
        <Label>Método</Label>
        <OptionGrid options={METHODS.map((m) => ({ value: m.value, label: m.label }))} value={method} onChange={setMethod} />
      </View>
      {method !== 'efectivo' ? <Input label="Referencia / autorización" value={reference} onChangeText={setReference} maxLength={120} error={errors.reference} /> : null}
      <Input label="Notas" value={notes} onChangeText={setNotes} maxLength={190} placeholder="Opcional" error={errors.notes} />
      <EvidencePicker file={file} onChange={setFile} gps={gps} locating={locating} error={errors.evidence} />
    </Sheet>
  );
}

export function VisitSheet({ client, visible, initialResult = 'no_localizado', onClose, onSaved }: { client: ClientLite | null; visible: boolean; initialResult?: VisitResult; onClose: () => void; onSaved: (message: string) => void }) {
  const toast = useToast();
  const { gps, locating } = useFormGps(visible);
  const [result, setResult] = useState<VisitResult>(initialResult);
  const [promiseDate, setPromiseDate] = useState<Date>(() => addDays(3));
  const [showPicker, setShowPicker] = useState(false);
  const [promiseAmount, setPromiseAmount] = useState('');
  const [notes, setNotes] = useState('');
  const [file, setFile] = useState<UploadFile | null>(null);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});

  useEffect(() => {
    if (visible && client) {
      setResult(initialResult);
      setPromiseDate(addDays(3));
      setPromiseAmount(client.balance > 0 ? client.balance.toFixed(2) : '');
      setNotes('');
      setFile(null);
      setErrors({});
    }
  }, [visible, client, initialResult]);

  if (!client) return null;

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const isPromise = result === 'promesa';
      const res = await api.upload<{ message: string }>('/collection/visits', {
        client_id: client.id,
        result,
        promise_date: isPromise ? isoDate(promiseDate) : undefined,
        promise_amount: isPromise ? promiseAmount.replace(',', '.') : undefined,
        notes: notes.trim(),
        lat: gps?.lat,
        lng: gps?.lng,
        evidence: file ?? undefined,
      });
      onSaved(res.message);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        setErrors({ result: e.field('result'), promise_date: e.field('promise_date'), promise_amount: e.field('promise_amount'), notes: e.field('notes'), evidence: e.field('evidence') });
      } else {
        toast(errorMessage(e), 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Sheet
      visible={visible}
      onClose={onClose}
      title="Registrar visita"
      footer={
        <>
          <Button variant="secondary" onPress={onClose} style={{ flex: 1 }}>
            Cancelar
          </Button>
          <Button icon={CheckIcon} onPress={save} loading={saving} style={{ flex: 1.5 }}>
            Guardar visita
          </Button>
        </>
      }
    >
      <ClientHeader client={client} highlight={false} />
      <View>
        <Label>Resultado</Label>
        <OptionGrid options={RESULTS.map((r) => ({ value: r.value, label: r.label, icon: r.icon }))} value={result} onChange={setResult} />
        <FieldError message={errors.result} />
      </View>
      {result === 'promesa' ? (
        <Row gap={10} style={{ alignItems: 'flex-start' }}>
          <View style={{ flex: 1 }}>
            <Label>Pagará el</Label>
            <Pressable onPress={() => setShowPicker(true)} style={styles.dateField}>
              <CalendarDaysIcon size={18} color={colors.brand[600]} />
              <Text style={{ fontSize: 15 }}>{date(promiseDate)}</Text>
            </Pressable>
            <FieldError message={errors.promise_date} />
            {showPicker ? (
              <DateTimePicker
                value={promiseDate}
                mode="date"
                minimumDate={new Date()}
                display={Platform.OS === 'ios' ? 'inline' : 'default'}
                onChange={(event, d) => {
                  setShowPicker(Platform.OS === 'ios');
                  if (event.type === 'set' && d) setPromiseDate(d);
                  if (Platform.OS === 'ios' && event.type === 'set') setShowPicker(false);
                }}
              />
            ) : null}
          </View>
          <Input label="Monto" prefix="$" value={promiseAmount} onChangeText={setPromiseAmount} keyboardType="decimal-pad" error={errors.promise_amount} style={{ flex: 1 }} />
        </Row>
      ) : null}
      <Input label="Notas" value={notes} onChangeText={setNotes} multiline maxLength={190} placeholder="Con quién habló, observaciones…" error={errors.notes} />
      <EvidencePicker file={file} onChange={setFile} gps={gps} locating={locating} error={errors.evidence} />
    </Sheet>
  );
}

function addDays(n: number): Date {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return d;
}

const styles = StyleSheet.create({
  clientBox: { backgroundColor: colors.slate[50], borderRadius: radius.md, padding: 12 },
  thumb: { width: 64, height: 64, borderRadius: radius.sm, backgroundColor: colors.slate[100] },
  dateField: { flexDirection: 'row', alignItems: 'center', gap: 8, borderWidth: 1, borderColor: colors.slate[300], borderRadius: radius.sm, paddingHorizontal: 12, paddingVertical: 11, backgroundColor: colors.white },
});
