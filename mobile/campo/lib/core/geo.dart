import 'package:geolocator/geolocator.dart';

/// Coordenadas GPS para llegada, salida, evidencias y cobros.
class GeoPoint {
  const GeoPoint(this.lat, this.lng);

  final double lat;
  final double lng;

  Map<String, dynamic> toJson() => {'lat': lat, 'lng': lng};
}

/// Obtiene la ubicación actual sin bloquear el trabajo: si el GPS falla o no hay permiso
/// devuelve null y la acción se registra igual (el servidor acepta lat/lng vacíos).
class Geo {
  static String? lastError;

  static Future<GeoPoint?> current() async {
    lastError = null;
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        lastError = 'El GPS está apagado.';
        return null;
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        lastError = 'Sin permiso de ubicación.';
        return null;
      }
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 12)),
      );
      return GeoPoint(pos.latitude, pos.longitude);
    } catch (_) {
      try {
        final last = await Geolocator.getLastKnownPosition();
        if (last != null) return GeoPoint(last.latitude, last.longitude);
      } catch (_) {}
      lastError = 'No se pudo obtener la ubicación.';
      return null;
    }
  }
}
