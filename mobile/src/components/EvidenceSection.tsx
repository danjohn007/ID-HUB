import { Image } from 'expo-image';
import { useState } from 'react';
import { Alert, Modal, Pressable, StyleSheet, View } from 'react-native';
import { CameraIcon, MapPinIcon, PhotoIcon, TrashIcon } from 'react-native-heroicons/outline';

import { useToast } from './Toast';
import { Button, Card, OptionGrid, Row, Text } from './ui';

import { errorMessage, UploadFile } from '@/lib/api';
import { shortDateTime } from '@/lib/format';
import { currentPosition } from '@/lib/geo';
import { takePhoto } from '@/lib/photos';
import type { Attachment, Coords } from '@/lib/types';
import { colors, radius } from '@/theme';

/** Tipos de evidencia (config wisp.evidence_kinds, sin "firma", que tiene su propio bloque). */
export const EVIDENCE_KINDS = [
  { value: 'antes', label: 'Foto antes' },
  { value: 'durante', label: 'Foto durante' },
  { value: 'despues', label: 'Foto después' },
  { value: 'equipo', label: 'Foto del equipo' },
  { value: 'instalacion', label: 'Foto de instalación' },
  { value: 'otro', label: 'Otro' },
] as const;

type Kind = (typeof EVIDENCE_KINDS)[number]['value'];

/**
 * Evidencias fotográficas (sección 24): foto antes / durante / después / equipo / instalación,
 * con ubicación GPS y fecha/hora. Igual que el bloque «Evidencias fotográficas» de la web.
 */
export function EvidenceSection({
  attachments,
  editable,
  defaultKind = 'antes',
  onUpload,
  onDelete,
  title = 'Evidencias fotográficas',
  canDelete = () => true,
}: {
  attachments: Attachment[];
  editable: boolean;
  defaultKind?: Kind;
  onUpload: (file: UploadFile, kind: Kind, gps: Coords | null) => Promise<void>;
  onDelete?: (id: number) => Promise<void>;
  title?: string;
  canDelete?: (a: Attachment) => boolean;
}) {
  const toast = useToast();
  const [kind, setKind] = useState<Kind>(defaultKind);
  const [busy, setBusy] = useState<'camera' | 'library' | null>(null);
  const [preview, setPreview] = useState<Attachment | null>(null);

  const add = async (source: 'camera' | 'library') => {
    setBusy(source);
    try {
      const [file, gps] = await Promise.all([takePhoto(source), currentPosition()]);
      if (!file) return;
      await onUpload(file, kind, gps);
      toast(`${EVIDENCE_KINDS.find((k) => k.value === kind)?.label} guardada${gps ? ' con ubicación GPS' : ''}`);
    } catch (e) {
      toast(errorMessage(e), 'error');
    } finally {
      setBusy(null);
    }
  };

  const remove = (a: Attachment) => {
    Alert.alert('Eliminar evidencia', '¿Eliminar esta fotografía?', [
      { text: 'Cancelar', style: 'cancel' },
      {
        text: 'Eliminar',
        style: 'destructive',
        onPress: async () => {
          try {
            await onDelete?.(a.id);
            setPreview(null);
            toast('Evidencia eliminada', 'warning');
          } catch (e) {
            toast(errorMessage(e), 'error');
          }
        },
      },
    ]);
  };

  return (
    <Card title={title}>
      {editable ? (
        <View style={{ gap: 10, marginBottom: 14 }}>
          <OptionGrid options={EVIDENCE_KINDS.map((k) => ({ value: k.value, label: k.label }))} value={kind} onChange={setKind} columns={3} />
          <Row gap={8}>
            <Button icon={CameraIcon} onPress={() => add('camera')} loading={busy === 'camera'} disabled={!!busy} style={{ flex: 1.4 }}>
              Tomar foto
            </Button>
            <Button variant="secondary" icon={PhotoIcon} onPress={() => add('library')} loading={busy === 'library'} disabled={!!busy} style={{ flex: 1 }}>
              Galería
            </Button>
          </Row>
        </View>
      ) : null}

      {attachments.length ? (
        <View style={styles.grid}>
          {attachments.map((a) => (
            <Pressable key={a.id} style={styles.tile} onPress={() => setPreview(a)}>
              {a.is_image ? (
                <Image source={{ uri: a.url }} style={styles.thumb} contentFit="cover" transition={150} />
              ) : (
                <View style={[styles.thumb, { alignItems: 'center', justifyContent: 'center' }]}>
                  <Text weight="bold" style={{ color: colors.slate[500] }}>PDF</Text>
                </View>
              )}
              <View style={styles.caption}>
                <Text weight="semibold" style={{ fontSize: 11, color: colors.slate[700] }} numberOfLines={1}>
                  {a.kind_label}
                </Text>
                <Row gap={3}>
                  {a.location ? <MapPinIcon size={11} color={colors.success[600]} /> : null}
                  <Text style={{ fontSize: 10, color: colors.slate[500] }}>{shortDateTime(a.taken_at)}</Text>
                </Row>
              </View>
            </Pressable>
          ))}
        </View>
      ) : (
        <Text style={{ color: colors.slate[500], fontSize: 13 }}>Sin evidencias registradas.</Text>
      )}

      <Modal visible={!!preview} transparent animationType="fade" onRequestClose={() => setPreview(null)}>
        <Pressable style={styles.viewer} onPress={() => setPreview(null)}>
          {preview ? (
            <View style={{ width: '100%', gap: 12 }}>
              <Image source={{ uri: preview.url }} style={{ width: '100%', height: 420 }} contentFit="contain" />
              <Text weight="semibold" style={{ color: colors.white, textAlign: 'center' }}>
                {preview.kind_label} · {shortDateTime(preview.taken_at)}
                {preview.location ? ' · con GPS' : ''}
              </Text>
              {editable && onDelete && canDelete(preview) ? (
                <Button variant="danger" icon={TrashIcon} onPress={() => remove(preview)} style={{ alignSelf: 'center' }}>
                  Eliminar
                </Button>
              ) : null}
            </View>
          ) : null}
        </Pressable>
      </Modal>
    </Card>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', marginHorizontal: -4 },
  tile: { width: '33.33%', padding: 4 },
  thumb: { width: '100%', aspectRatio: 1, borderRadius: radius.md, backgroundColor: colors.slate[100] },
  caption: { paddingTop: 4, gap: 1 },
  viewer: { flex: 1, backgroundColor: 'rgba(7,19,48,0.92)', alignItems: 'center', justifyContent: 'center', padding: 16 },
});
