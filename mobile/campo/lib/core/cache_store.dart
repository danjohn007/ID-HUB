import 'dart:convert';
import 'dart:io' as io;

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Respuesta guardada en el teléfono y la hora en que llegó del servidor.
class CacheEntry {
  CacheEntry(this.data, this.savedAt);

  final Map<String, dynamic> data;
  final DateTime? savedAt;
}

/// Copia local de las respuestas de la API para trabajar sin señal. En el teléfono cada respuesta es un
/// archivo JSON en la carpeta de la app (caben decenas de tickets y clientes sin hacer lento el arranque);
/// en la versión web va en shared_preferences.
class CacheStore {
  static const _prefix = 'cache:';
  static io.Directory? _dir;

  static Future<io.Directory> _folder() async {
    if (_dir != null) return _dir!;
    final base = await getApplicationSupportDirectory();
    final dir = io.Directory('${base.path}/api-cache');
    await dir.create(recursive: true);
    return _dir = dir;
  }

  static Future<io.File> _file(String key) async => io.File('${(await _folder()).path}/${Uri.encodeComponent(key)}.json');

  static Future<CacheEntry?> read(String key) async {
    try {
      final String? raw;
      if (kIsWeb) {
        raw = (await SharedPreferences.getInstance()).getString('$_prefix$key');
      } else {
        final file = await _file(key);
        raw = await file.exists() ? await file.readAsString() : null;
      }
      if (raw == null) return null;
      final saved = jsonDecode(raw) as Map<String, dynamic>;
      return CacheEntry(Map<String, dynamic>.from(saved['data'] as Map), DateTime.tryParse(saved['at'] as String? ?? ''));
    } catch (_) {
      return null;
    }
  }

  static Future<void> write(String key, Map<String, dynamic> data, {DateTime? savedAt}) async {
    final raw = jsonEncode({'at': (savedAt ?? DateTime.now()).toIso8601String(), 'data': data});
    try {
      if (kIsWeb) {
        await (await SharedPreferences.getInstance()).setString('$_prefix$key', raw);
      } else {
        // Se escribe aparte y se renombra: un cierre a medio guardar no deja el archivo corrupto.
        final file = await _file(key);
        final tmp = io.File('${file.path}.tmp');
        await tmp.writeAsString(raw, flush: true);
        await tmp.rename(file.path);
      }
    } catch (_) {}
  }

  /// Modifica la copia guardada (acción hecha sin señal) conservando la hora de descarga.
  /// Si esa respuesta no está guardada no hace nada.
  static Future<void> update(String key, void Function(Map<String, dynamic> data) change) async {
    final entry = await read(key);
    if (entry == null) return;
    change(entry.data);
    await write(key, entry.data, savedAt: entry.savedAt);
  }

  /// Claves guardadas que empiezan con [prefix] (p. ej. todas las listas de clientes).
  static Future<List<String>> keys(String prefix) async {
    try {
      if (kIsWeb) {
        return (await SharedPreferences.getInstance()).getKeys().where((k) => k.startsWith('$_prefix$prefix')).map((k) => k.substring(_prefix.length)).toList();
      }
      final names = await (await _folder()).list().where((f) => f.path.endsWith('.json')).map((f) => f.uri.pathSegments.last).toList();
      return names.map((n) => Uri.decodeComponent(n.substring(0, n.length - 5))).where((k) => k.startsWith(prefix)).toList();
    } catch (_) {
      return const [];
    }
  }

  /// Borra lo descargado hace más de [age] (tickets ya cerrados, clientes de otras rutas...).
  static Future<void> prune(Duration age) async {
    if (kIsWeb) return;
    try {
      final limit = DateTime.now().subtract(age);
      await for (final f in (await _folder()).list()) {
        if (f is io.File && (await f.lastModified()).isBefore(limit)) await f.delete();
      }
      // La caché de versiones anteriores vivía en shared_preferences.
      final prefs = await SharedPreferences.getInstance();
      for (final k in prefs.getKeys().where((k) => k.startsWith(_prefix)).toList()) {
        await prefs.remove(k);
      }
    } catch (_) {}
  }

  static Future<void> clear() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      for (final k in prefs.getKeys().where((k) => k.startsWith(_prefix)).toList()) {
        await prefs.remove(k);
      }
      if (!kIsWeb) {
        final dir = await _folder();
        if (await dir.exists()) await dir.delete(recursive: true);
        _dir = null;
      }
    } catch (_) {}
  }
}

/// Nombres de las respuestas guardadas: los usan las pantallas, la descarga del día y los cambios locales.
class CacheKeys {
  static String today(String type) => 'today-$type';
  static String ticket(int id) => 'ticket-$id';
  static String installation(int id) => 'installation-$id';
  static String client(int id) => 'client-$id';
  static String clientTickets(int id) => 'client-tickets-$id';
  static String visits(int clientId) => 'visits-$clientId';
  static String clients(String search) => 'clients-$search';
  static String tickets(String status) => 'tickets-$status';
  static String installations(String status) => 'installations-$status';
  static String pending(String search) => 'pending-$search';
  static const pendingHome = 'pending-home';
  static const inventory = 'inventory';
  static const cashCut = 'cash-cut';
  static const cashCuts = 'cash-cuts';
}
