import { Image } from 'expo-image';
import { useRef, useState } from 'react';
import { Modal, StyleSheet, View } from 'react-native';
import { PencilSquareIcon } from 'react-native-heroicons/outline';
import { SafeAreaView } from 'react-native-safe-area-context';
import SignatureScreen, { SignatureViewRef } from 'react-native-signature-canvas';

import { useToast } from './Toast';
import { Button, Card, Input, Row, Text } from './ui';

import { errorMessage } from '@/lib/api';
import { colors, radius } from '@/theme';

/**
 * Firma de conformidad del cliente (sección 23). Igual que el bloque «Firma» de la web:
 * muestra la firma guardada y permite capturarla de nuevo con el nombre de quien firma.
 */
export function SignatureSection({
  signature,
  defaultName,
  editable,
  onSave,
  title = 'Firma de conformidad',
}: {
  signature: { url: string; signed_by_name: string; signed_at?: string | null } | null;
  defaultName: string;
  editable: boolean;
  onSave: (signedBy: string, dataUrl: string) => Promise<void>;
  title?: string;
}) {
  const toast = useToast();
  const ref = useRef<SignatureViewRef>(null);
  const [open, setOpen] = useState(false);
  const [name, setName] = useState(signature?.signed_by_name || defaultName);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleOK = async (dataUrl: string) => {
    if (name.trim().length < 3) {
      setError('Escribe el nombre de quien firma (mínimo 3 letras).');
      return;
    }
    setSaving(true);
    try {
      await onSave(name.trim(), dataUrl);
      setOpen(false);
      toast('Firma guardada');
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card title={title}>
      {signature ? (
        <View style={styles.saved}>
          <Image source={{ uri: signature.url }} style={styles.image} contentFit="contain" />
          <Text style={{ fontSize: 12, color: colors.slate[500], textAlign: 'center' }}>
            Firmó: <Text weight="semibold" style={{ fontSize: 12 }}>{signature.signed_by_name}</Text>
          </Text>
        </View>
      ) : (
        <Text style={{ color: colors.slate[500], fontSize: 13, marginBottom: editable ? 12 : 0 }}>Aún no hay firma del cliente.</Text>
      )}
      {editable ? (
        <Button variant={signature ? 'secondary' : 'primary'} icon={PencilSquareIcon} onPress={() => { setError(null); setOpen(true); }} style={{ marginTop: signature ? 12 : 0 }}>
          {signature ? 'Capturar de nuevo' : 'Capturar firma del cliente'}
        </Button>
      ) : null}

      <Modal visible={open} animationType="slide" onRequestClose={() => setOpen(false)}>
        <SafeAreaView style={{ flex: 1, backgroundColor: colors.surface }}>
          <View style={{ padding: 16, gap: 12 }}>
            <Text weight="bold" style={{ fontSize: 18, color: colors.navy[900] }}>
              Firma del cliente
            </Text>
            <Input label="Nombre de quien firma" value={name} onChangeText={setName} autoCapitalize="words" error={error} />
            <Text style={{ fontSize: 12, color: colors.slate[500] }}>Pide al cliente que firme dentro del recuadro.</Text>
          </View>
          <View style={styles.pad}>
            <SignatureScreen
              ref={ref}
              onOK={handleOK}
              onEmpty={() => setError('Captura la firma del cliente.')}
              descriptionText=""
              imageType="image/png"
              penColor="#0b1b3a"
              backgroundColor="#ffffff"
              webStyle={`.m-signature-pad{box-shadow:none;border:none;margin:0} .m-signature-pad--body{border:none} .m-signature-pad--footer{display:none} body,html{height:100%}`}
              autoClear={false}
            />
          </View>
          <Row style={{ padding: 16 }} gap={10}>
            <Button variant="secondary" onPress={() => setOpen(false)} style={{ flex: 1 }}>
              Cancelar
            </Button>
            <Button variant="ghost" onPress={() => ref.current?.clearSignature()} style={{ flex: 1 }}>
              Limpiar
            </Button>
            <Button icon={PencilSquareIcon} loading={saving} onPress={() => ref.current?.readSignature()} style={{ flex: 1.4 }}>
              Guardar
            </Button>
          </Row>
        </SafeAreaView>
      </Modal>
    </Card>
  );
}

const styles = StyleSheet.create({
  saved: { gap: 6, alignItems: 'stretch' },
  image: { height: 120, borderRadius: radius.md, borderWidth: 1, borderColor: colors.slate[200], backgroundColor: colors.white },
  pad: { flex: 1, marginHorizontal: 16, borderRadius: radius.lg, borderWidth: 2, borderStyle: 'dashed', borderColor: colors.slate[300], overflow: 'hidden', backgroundColor: colors.white },
});
