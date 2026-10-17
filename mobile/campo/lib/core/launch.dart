import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'geo.dart';

/// Llamar, WhatsApp y navegación con la app de mapas del teléfono.
class Launch {
  static Future<void> call(BuildContext context, String? phone) => _open(context, phone == null ? null : 'tel:${_digits(phone)}');

  /// WhatsApp: números de 10 dígitos se toman como México (+52).
  static Future<void> whatsapp(BuildContext context, String? phone, {String? text}) {
    if (phone == null) return _open(context, null);
    var d = _digits(phone);
    if (d.length == 10) d = '52$d';
    final q = text == null ? '' : '?text=${Uri.encodeComponent(text)}';
    return _open(context, 'https://wa.me/$d$q');
  }

  /// Abre la ruta en Google Maps en auto y saliendo de la ubicación GPS actual del teléfono.
  static Future<void> navigate(BuildContext context, String? url) async {
    if (url == null || url.isEmpty) return _open(context, null);
    final messenger = ScaffoldMessenger.of(context);
    messenger.showSnackBar(const SnackBar(content: Text('Ubicando tu posición…'), duration: Duration(seconds: 8)));
    final here = await Geo.quick();
    messenger.hideCurrentSnackBar();
    if (!context.mounted) return;
    return _open(context, directions(url, from: here));
  }

  static Future<void> mapsTo(BuildContext context, double? lat, double? lng) =>
      navigate(context, lat == null || lng == null ? null : 'https://www.google.com/maps/dir/?api=1&destination=$lat,$lng');

  /// Ajusta una liga de indicaciones de Google Maps: en auto (sin travelmode, Maps usa el último modo
  /// del teléfono, p. ej. transporte público) y con origen en [from] (sin origin, Maps toma su propia
  /// ubicación, que puede estar desfasada). Otras ligas se regresan igual.
  static String directions(String url, {GeoPoint? from}) {
    final uri = Uri.tryParse(url);
    if (uri == null || !uri.host.contains('google.') || !uri.path.startsWith('/maps/dir')) return url;
    final q = {...uri.queryParameters};
    q.putIfAbsent('travelmode', () => 'driving');
    if (from != null) q.putIfAbsent('origin', () => '${from.lat},${from.lng}');
    return uri.replace(queryParameters: q).toString();
  }

  static String _digits(String s) => s.replaceAll(RegExp(r'[^0-9+]'), '').replaceFirst('+', '');

  static Future<void> _open(BuildContext context, String? url) async {
    final messenger = ScaffoldMessenger.of(context);
    if (url == null || url.isEmpty) {
      messenger.showSnackBar(const SnackBar(content: Text('No hay datos para esta acción.')));
      return;
    }
    final ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!ok) messenger.showSnackBar(const SnackBar(content: Text('No se pudo abrir la aplicación.')));
  }
}
