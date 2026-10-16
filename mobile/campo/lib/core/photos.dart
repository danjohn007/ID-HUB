import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

/// Toma de fotografías de evidencia (sección 24): cámara o galería, comprimidas para subir con poca señal.
class Photos {
  static final _picker = ImagePicker();

  static Future<MultipartFile?> pick(BuildContext context, {bool allowGallery = true}) async {
    ImageSource? source = ImageSource.camera;
    if (allowGallery) {
      source = await showModalBottomSheet<ImageSource>(
        context: context,
        showDragHandle: true,
        builder: (ctx) => SafeArea(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            ListTile(leading: const Icon(Icons.photo_camera_outlined), title: const Text('Tomar foto'), onTap: () => Navigator.pop(ctx, ImageSource.camera)),
            ListTile(leading: const Icon(Icons.photo_library_outlined), title: const Text('Elegir de la galería'), onTap: () => Navigator.pop(ctx, ImageSource.gallery)),
            const SizedBox(height: 8),
          ]),
        ),
      );
    }
    if (source == null) return null;
    final file = await _picker.pickImage(source: source, imageQuality: 70, maxWidth: 1600, maxHeight: 1600);
    if (file == null) return null;
    final bytes = await file.readAsBytes();
    final name = file.name.isNotEmpty ? file.name : 'foto.jpg';
    return MultipartFile.fromBytes(bytes, filename: name);
  }
}
