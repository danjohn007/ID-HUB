import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

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

  static Future<void> navigate(BuildContext context, String? url) => _open(context, url);

  static Future<void> mapsTo(BuildContext context, double? lat, double? lng) =>
      _open(context, lat == null || lng == null ? null : 'https://www.google.com/maps/dir/?api=1&destination=$lat,$lng');

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
