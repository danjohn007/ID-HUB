import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:image_picker/image_picker.dart';

import 'geo.dart';

/// Foto de evidencia tomada en el momento: bytes, hora de la captura y GPS de ese instante.
class EvidencePhoto {
  EvidencePhoto({required this.bytes, required this.name, required this.takenAt, this.gps});

  final Uint8List bytes;
  final String name;
  final DateTime takenAt;
  final GeoPoint? gps;

  MultipartFile toMultipart() => MultipartFile.fromBytes(bytes, filename: name);

  /// Hora de la captura en UTC (ISO 8601) para el campo taken_at del servidor.
  String get takenAtIso => takenAt.toUtc().toIso8601String();
}

/// Toma de fotografías de evidencia (sección 24): solo cámara (no galería) para que la foto,
/// la hora y la ubicación correspondan al momento del trabajo. Comprimidas para subir con poca señal.
class Photos {
  static final _picker = ImagePicker();

  static Future<EvidencePhoto?> capture() async {
    final file = await _picker.pickImage(source: ImageSource.camera, imageQuality: 70, maxWidth: 1600, maxHeight: 1600);
    if (file == null) return null;
    // Hora y GPS en cuanto la cámara devuelve la foto (no al subirla).
    final takenAt = DateTime.now();
    final gps = await Geo.current();
    final bytes = await file.readAsBytes();
    final name = file.name.isNotEmpty ? file.name : 'foto.jpg';
    return EvidencePhoto(bytes: bytes, name: name, takenAt: takenAt, gps: gps);
  }
}
