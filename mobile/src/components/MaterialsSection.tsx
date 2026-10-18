import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { Alert, FlatList, Pressable, StyleSheet, View } from 'react-native';
import { ArrowUturnLeftIcon, CubeIcon, MagnifyingGlassIcon, PlusIcon } from 'react-native-heroicons/outline';

import { useToast } from './Toast';
import { Badge, Button, Card, Input, OptionGrid, Row, Sheet, Text } from './ui';

import { api, ApiError, errorMessage } from '@/lib/api';
import { qty, shortDateTime } from '@/lib/format';
import type { InventoryItem, Material } from '@/lib/types';
import { colors, radius, toTone } from '@/theme';

/**
 * Materiales usados (sección 23): se descuentan del inventario y se pueden devolver si se
 * registraron por error. Igual que el bloque «Materiales usados» del ticket en la web.
 */
export function MaterialsSection({
  materials,
  editable,
  onAdd,
  onReturn,
}: {
  materials: Material[];
  editable: boolean;
  onAdd: (data: { item_id: number; quantity: number; type: 'instalacion' | 'salida'; serial?: string }) => Promise<void>;
  onReturn: (movementId: number) => Promise<void>;
}) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [item, setItem] = useState<InventoryItem | null>(null);
  const [quantity, setQuantity] = useState('1');
  const [type, setType] = useState<'instalacion' | 'salida'>('instalacion');
  const [serial, setSerial] = useState('');
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});

  const items = useQuery({
    queryKey: ['inventory-items'],
    queryFn: () => api.get<{ data: InventoryItem[] }>('/inventory/items').then((r) => r.data),
    enabled: open,
    staleTime: 60_000,
  });

  const filtered = useMemo(() => {
    const t = search.trim().toLowerCase();
    return (items.data ?? []).filter((i) => !t || i.name.toLowerCase().includes(t) || i.sku?.toLowerCase().includes(t));
  }, [items.data, search]);

  const reset = () => {
    setItem(null);
    setQuantity('1');
    setType('instalacion');
    setSerial('');
    setSearch('');
    setErrors({});
  };

  const save = async () => {
    if (!item) {
      setErrors({ item_id: 'Elige un artículo del inventario.' });
      return;
    }
    setSaving(true);
    setErrors({});
    try {
      await onAdd({ item_id: item.id, quantity: Number(quantity.replace(',', '.')), type, serial: serial.trim() || undefined });
      toast('Material registrado y descontado del inventario');
      setOpen(false);
      reset();
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        setErrors({ item_id: e.field('item_id'), quantity: e.field('quantity'), serial: e.field('serial') });
        if (!Object.keys(e.errors).length) toast(e.message, 'error');
      } else {
        toast(errorMessage(e), 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  const giveBack = (m: Material) => {
    Alert.alert('Devolver material', `¿Devolver ${qty(m.quantity)} ${m.item?.unit ?? ''} de ${m.item?.name} al almacén?`, [
      { text: 'Cancelar', style: 'cancel' },
      {
        text: 'Devolver',
        onPress: async () => {
          try {
            await onReturn(m.id);
            toast('Material devuelto al inventario', 'warning');
          } catch (e) {
            toast(errorMessage(e), 'error');
          }
        },
      },
    ]);
  };

  return (
    <Card
      title="Materiales usados"
      right={
        editable ? (
          <Button size="sm" icon={PlusIcon} onPress={() => setOpen(true)}>
            Registrar
          </Button>
        ) : undefined
      }
    >
      {materials.length ? (
        materials.map((m, i) => (
          <View key={m.id} style={[styles.row, i > 0 ? styles.divider : null, m.type.value === 'devolucion' ? { opacity: 0.6 } : null]}>
            <View style={{ flex: 1, gap: 3 }}>
              <Text weight="medium" numberOfLines={1}>
                {m.item?.name}
              </Text>
              <Row gap={6} style={{ flexWrap: 'wrap' }}>
                <Badge tone={toTone(m.type.color)} size="xs">
                  {m.type.label}
                </Badge>
                <Text style={{ fontSize: 11.5, color: colors.slate[500] }}>
                  {shortDateTime(m.created_at)}
                  {m.user ? ` · ${m.user.name}` : ''}
                  {m.serial ? ` · S/N ${m.serial}` : ''}
                </Text>
              </Row>
            </View>
            <Text weight="semibold" style={{ color: m.type.value === 'devolucion' ? colors.success[700] : colors.slate[800] }}>
              {m.type.value === 'devolucion' ? '+' : ''}
              {qty(m.quantity)} {m.item?.unit}
            </Text>
            {editable && m.returnable ? (
              <Pressable onPress={() => giveBack(m)} hitSlop={10} accessibilityLabel="Devolver al almacén">
                <ArrowUturnLeftIcon size={18} color={colors.slate[400]} />
              </Pressable>
            ) : null}
          </View>
        ))
      ) : (
        <Text style={{ color: colors.slate[500], fontSize: 13 }}>Sin materiales registrados.</Text>
      )}

      <Sheet
        visible={open}
        onClose={() => setOpen(false)}
        title="Registrar material"
        footer={
          <>
            <Button variant="secondary" onPress={() => setOpen(false)} style={{ flex: 1 }}>
              Cancelar
            </Button>
            <Button icon={PlusIcon} loading={saving} onPress={save} style={{ flex: 1.5 }}>
              Registrar material
            </Button>
          </>
        }
      >
        {item ? (
          <Pressable onPress={() => setItem(null)} style={[styles.picked]}>
            <CubeIcon size={22} color={colors.brand[600]} />
            <View style={{ flex: 1 }}>
              <Text weight="semibold">{item.name}</Text>
              <Text style={{ fontSize: 12, color: colors.slate[500] }}>
                {item.sku} · Existencia {item.stock_label}
              </Text>
            </View>
            <Text weight="semibold" style={{ color: colors.brand[600], fontSize: 12 }}>
              Cambiar
            </Text>
          </Pressable>
        ) : (
          <View style={{ gap: 8 }}>
            <Input placeholder="Buscar artículo o SKU…" value={search} onChangeText={setSearch} error={errors.item_id} autoCorrect={false} />
            <View style={{ maxHeight: 260, borderWidth: 1, borderColor: colors.slate[200], borderRadius: radius.md }}>
              <FlatList
                data={filtered}
                keyExtractor={(i) => String(i.id)}
                nestedScrollEnabled
                keyboardShouldPersistTaps="handled"
                ListEmptyComponent={
                  <View style={{ padding: 16, alignItems: 'center', gap: 6 }}>
                    <MagnifyingGlassIcon size={20} color={colors.slate[400]} />
                    <Text style={{ color: colors.slate[500], fontSize: 13 }}>{items.isLoading ? 'Cargando inventario…' : 'Sin artículos'}</Text>
                  </View>
                }
                renderItem={({ item: i }) => (
                  <Pressable onPress={() => setItem(i)} disabled={i.stock <= 0} style={({ pressed }) => [styles.itemRow, pressed ? { backgroundColor: colors.slate[50] } : null, i.stock <= 0 ? { opacity: 0.45 } : null]}>
                    <View style={{ flex: 1 }}>
                      <Text weight="medium" numberOfLines={1}>
                        {i.name}
                      </Text>
                      <Text style={{ fontSize: 11.5, color: colors.slate[500] }}>{i.sku}</Text>
                    </View>
                    <Badge tone={toTone(i.stock_state)} size="xs">
                      {i.stock_label}
                    </Badge>
                  </Pressable>
                )}
              />
            </View>
          </View>
        )}
        <Row gap={10} style={{ alignItems: 'flex-start' }}>
          <Input label="Cantidad" value={quantity} onChangeText={setQuantity} keyboardType="decimal-pad" error={errors.quantity} style={{ flex: 1 }} />
          <Input label="Serie (opcional)" value={serial} onChangeText={setSerial} autoCapitalize="characters" error={errors.serial} style={{ flex: 1.6 }} />
        </Row>
        <OptionGrid
          options={[
            { value: 'instalacion', label: 'Instalación' },
            { value: 'salida', label: 'Salida' },
          ]}
          value={type}
          onChange={setType}
        />
      </Sheet>
    </Card>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 9 },
  divider: { borderTopWidth: 1, borderTopColor: colors.slate[100] },
  picked: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderRadius: radius.md, borderWidth: 1, borderColor: colors.brand[200], backgroundColor: colors.brand[50] },
  itemRow: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 12, paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: colors.slate[100] },
});
