import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../firebase_options.dart';
import 'api.dart';

/// Notificaciones push (Firebase Cloud Messaging): ticket u orden de instalación asignada,
/// ruta planeada por la oficina y avisos de automatizaciones.
///
/// El servidor manda en `data`: `type` (ticket, installation_order, route, alert, test) e `id`.
/// Si la app se compiló sin la configuración de Firebase, todo esto queda desactivado.
class Push {
  static final messengerKey = GlobalKey<ScaffoldMessengerState>();

  static bool _ready = false;
  static String? token;
  static StreamSubscription<String>? _refresh;

  /// Pantalla que abre el aviso tocado (la registra el Shell cuando hay sesión).
  static void Function(Map<String, dynamic> data)? _handler;
  static Map<String, dynamic>? _pending;

  static bool get enabled => _ready;

  static Future<void> init() async {
    if (kIsWeb) return;
    try {
      await Firebase.initializeApp(options: DefaultFirebaseOptions.currentPlatform);
      _ready = true;
    } catch (e) {
      debugPrint('Push desactivado: $e');
      return;
    }
    FirebaseMessaging.onMessage.listen(_foreground);
    FirebaseMessaging.onMessageOpenedApp.listen((m) => _open(m.data));
    // La app se abrió tocando una notificación estando cerrada.
    unawaited(FirebaseMessaging.instance.getInitialMessage().then((m) {
      if (m != null) _open(m.data);
    }));
  }

  /// Pide permiso y registra el dispositivo en el servidor para el usuario de la sesión.
  static Future<void> register(ApiClient api) async {
    if (!_ready) return;
    try {
      final settings = await FirebaseMessaging.instance.requestPermission();
      if (settings.authorizationStatus == AuthorizationStatus.denied) return;
      // Token de registro clásico (lo que el servidor manda en message.token de la API HTTP v1).
      // firebase_messaging 17 lo marca obsoleto a favor del FID (register/onRegistered), que usa otro
      // destino de envío; se migrará cuando el servidor lo soporte.
      // ignore: deprecated_member_use
      final t = await FirebaseMessaging.instance.getToken();
      if (t == null) return;
      token = t;
      await _send(api, t);
      await _refresh?.cancel();
      // ignore: deprecated_member_use
      _refresh = FirebaseMessaging.instance.onTokenRefresh.listen((t) {
        token = t;
        _send(api, t).catchError((_) {});
      });
    } catch (e) {
      debugPrint('No se pudo registrar el dispositivo para push: $e');
    }
  }

  /// Al cerrar sesión el teléfono deja de recibir avisos de ese usuario.
  static Future<void> unregister() async {
    await _refresh?.cancel();
    _refresh = null;
    token = null;
    if (!_ready) return;
    try {
      // ignore: deprecated_member_use
      await FirebaseMessaging.instance.deleteToken();
    } catch (_) {}
  }

  static Future<void> _send(ApiClient api, String t) => api.post('/push-tokens', data: {
        'token': t,
        'platform': defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android',
      });

  /// El Shell abre los detalles; si el aviso llegó antes de entrar, se entrega al registrarse.
  static void attach(void Function(Map<String, dynamic> data) handler) {
    _handler = handler;
    final pending = _pending;
    if (pending != null) {
      _pending = null;
      WidgetsBinding.instance.addPostFrameCallback((_) => handler(pending));
    }
  }

  static void detach(void Function(Map<String, dynamic> data) handler) {
    if (_handler == handler) _handler = null;
  }

  static void _open(Map<String, dynamic> data) {
    final handler = _handler;
    if (handler == null) {
      _pending = data;
    } else {
      handler(data);
    }
  }

  /// Con la app abierta Android no muestra la notificación: se avisa dentro de la app.
  static void _foreground(RemoteMessage message) {
    final n = message.notification;
    if (n == null) return;
    final canOpen = ['ticket', 'installation_order', 'route'].contains(message.data['type']);
    messengerKey.currentState?.showSnackBar(SnackBar(
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        if ((n.title ?? '').isNotEmpty) Text(n.title!, style: const TextStyle(fontWeight: FontWeight.w700)),
        if ((n.body ?? '').isNotEmpty) Text(n.body!, maxLines: 3, overflow: TextOverflow.ellipsis),
      ]),
      duration: const Duration(seconds: 6),
      action: canOpen ? SnackBarAction(label: 'Ver', onPressed: () => _open(message.data)) : null,
    ));
  }
}
