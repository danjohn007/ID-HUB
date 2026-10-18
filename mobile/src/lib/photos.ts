import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import { Alert } from 'react-native';

import type { UploadFile } from './api';

/**
 * Evidencia fotográfica (sección 24): toma la foto con la cámara (o de la galería),
 * la reduce a 1600 px / JPEG 75 % para subirla rápido con poca señal.
 */
export async function takePhoto(source: 'camera' | 'library' = 'camera'): Promise<UploadFile | null> {
  if (source === 'camera') {
    const perm = await ImagePicker.requestCameraPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Permiso de cámara', 'Activa el permiso de cámara en los ajustes del teléfono para tomar evidencias.');
      return null;
    }
  } else {
    const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) {
      Alert.alert('Permiso de fotos', 'Activa el acceso a tus fotos en los ajustes del teléfono.');
      return null;
    }
  }

  const options: ImagePicker.ImagePickerOptions = { mediaTypes: ['images'], quality: 0.8, exif: false };
  const result = source === 'camera' ? await ImagePicker.launchCameraAsync(options) : await ImagePicker.launchImageLibraryAsync(options);
  if (result.canceled || !result.assets?.[0]) return null;

  const asset = result.assets[0];
  const resize = (asset.width ?? 0) > (asset.height ?? 0) ? { width: Math.min(asset.width ?? 1600, 1600) } : { height: Math.min(asset.height ?? 1600, 1600) };
  const rendered = await ImageManipulator.manipulate(asset.uri).resize(resize).renderAsync();
  const out = await rendered.saveAsync({ compress: 0.75, format: SaveFormat.JPEG });

  return { uri: out.uri, name: `evidencia-${Date.now()}.jpg`, type: 'image/jpeg' };
}

/** Pregunta cámara o galería. */
export function choosePhoto(): Promise<UploadFile | null> {
  return new Promise((resolve) => {
    Alert.alert('Agregar foto', undefined, [
      { text: 'Tomar foto', onPress: () => takePhoto('camera').then(resolve) },
      { text: 'Elegir de la galería', onPress: () => takePhoto('library').then(resolve) },
      { text: 'Cancelar', style: 'cancel', onPress: () => resolve(null) },
    ]);
  });
}
