import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';
import 'api.dart';

/// Usuario del personal autenticado (técnico, cobrador, supervisor...).
class StaffUser {
  StaffUser(this.json);

  final Map<String, dynamic> json;

  int get id => json['id'] as int;
  String get name => json['name'] as String? ?? '';
  String get email => json['email'] as String? ?? '';
  String? get phone => json['phone'] as String?;
  String get role => json['role'] as String? ?? '';
  String get roleLabel => json['role_label'] as String? ?? role;
}

/// WISP del usuario (marca blanca: nombre, logo y colores).
class TenantInfo {
  TenantInfo(this.json);

  final Map<String, dynamic> json;

  String get name => json['name'] as String? ?? '';
  String? get logoUrl => json['logo_url'] as String?;

  Color get primary => parseColor(json['primary_color'] as String?) ?? const Color(AppConfig.brandColor);

  static Color? parseColor(String? hex) {
    if (hex == null) return null;
    final h = hex.replaceAll('#', '');
    if (h.length != 6) return null;
    final v = int.tryParse(h, radix: 16);
    return v == null ? null : Color(0xFF000000 | v);
  }
}

enum AppMode { tecnico, cobrador }

/// Sesión: servidor, token, usuario, WISP y permisos. El token vive en almacenamiento seguro.
class Session extends ChangeNotifier {
  Session() {
    api = ApiClient(server: AppConfig.defaultServer, onUnauthorized: _expired);
  }

  static const _storage = FlutterSecureStorage();

  late final ApiClient api;
  String server = AppConfig.defaultServer;
  String? _token;
  StaffUser? user;
  TenantInfo? tenant;
  List<String> abilities = const [];
  bool restoring = true;
  String? expiredMessage;

  bool get loggedIn => _token != null && user != null;

  bool can(String ability) => abilities.contains('*') || abilities.contains(ability);

  bool get isTechnician => can('field.work');
  bool get isCollector => can('collection.field');

  /// Modo principal de la app: el técnico ve órdenes y tickets; el cobrador, cobranza.
  AppMode get mode => isTechnician ? AppMode.tecnico : AppMode.cobrador;

  Color get brand => tenant?.primary ?? const Color(AppConfig.brandColor);

  Future<void> restore() async {
    final prefs = await SharedPreferences.getInstance();
    server = prefs.getString('server') ?? AppConfig.defaultServer;
    try {
      _token = await _storage.read(key: 'token');
    } catch (_) {
      _token = null;
    }
    api.configure(server: server, token: _token);
    if (_token != null) {
      try {
        final me = await api.get('/me');
        _apply(me);
      } on ApiException catch (e) {
        if (e.offline) {
          // Sin señal: se usa el último perfil guardado para poder trabajar con la caché.
          final cached = prefs.getString('profile');
          if (cached != null) _apply(Map<String, dynamic>.from(jsonDecode(cached) as Map));
        } else {
          await _clear();
        }
      }
    }
    restoring = false;
    notifyListeners();
  }

  Future<void> setServer(String value) async {
    server = ApiClient.normalizeServer(value);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('server', server);
    api.configure(server: server, token: _token);
    notifyListeners();
  }

  Future<void> login(String email, String password) async {
    final res = await api.post('/auth/login', data: {
      'email': email.trim().toLowerCase(),
      'password': password,
      'device_name': _deviceName(),
    });
    final token = res['token'] as String;
    final abilities = List<String>.from(res['abilities'] as List? ?? []);
    final probe = abilities.contains('*') || abilities.contains('field.work') || abilities.contains('collection.field');
    if (!probe) {
      api.configure(server: server, token: token);
      await api.post('/auth/logout').catchError((_) => <String, dynamic>{});
      api.configure(server: server, token: null);
      throw ApiException('Tu usuario no tiene acceso a la app de campo. Usa el panel web.');
    }
    _token = token;
    await _storage.write(key: 'token', value: token);
    api.configure(server: server, token: token);
    _apply({'user': res['user'], 'tenant': res['tenant'], 'abilities': abilities});
    expiredMessage = null;
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.post('/auth/logout');
    } catch (_) {}
    await _clear();
    notifyListeners();
  }

  void _apply(Map<String, dynamic> data) {
    user = StaffUser(Map<String, dynamic>.from(data['user'] as Map));
    tenant = data['tenant'] is Map ? TenantInfo(Map<String, dynamic>.from(data['tenant'] as Map)) : null;
    abilities = List<String>.from(data['abilities'] as List? ?? data['permissions'] as List? ?? const []);
    SharedPreferences.getInstance().then((p) => p.setString('profile', jsonEncode({'user': user!.json, 'tenant': tenant?.json, 'abilities': abilities})));
  }

  void _expired() {
    if (_token == null) return;
    expiredMessage = 'Tu sesión expiró. Inicia sesión de nuevo.';
    _clear().then((_) => notifyListeners());
  }

  Future<void> _clear() async {
    _token = null;
    user = null;
    tenant = null;
    abilities = const [];
    api.configure(server: server, token: null);
    try {
      await _storage.delete(key: 'token');
    } catch (_) {}
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('profile');
    await ApiClient.clearCache();
  }

  String _deviceName() {
    if (kIsWeb) return 'App campo (web)';
    return 'App campo (${defaultTargetPlatform.name})';
  }
}
