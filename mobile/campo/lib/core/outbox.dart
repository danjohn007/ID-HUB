import 'dart:async';
import 'dart:convert';
import 'dart:io' as io;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'local_patch.dart';
import 'uuid.dart';

/// Archivo que acompaña a una acción: foto de evidencia, firma o comprobante.
class OutboxFile {
  const OutboxFile(this.bytes, this.name);

  final Uint8List bytes;
  final String name;
}

/// Acción guardada sin conexión. [id] es también su clave de idempotencia (Idempotency-Key):
/// si el servidor ya la recibió, el reintento devuelve el resultado original sin duplicarla.
class OutboxItem {
  OutboxItem({
    required this.id,
    required this.userId,
    required this.method,
    required this.path,
    required this.fields,
    required this.label,
    required this.createdAt,
    this.meta = const {},
    this.fileField,
    this.fileName,
    this.filePath,
    this.fileB64,
    this.error,
  });

  factory OutboxItem.fromJson(Map<String, dynamic> j) => OutboxItem(
        id: j['id'] as String,
        userId: j['user_id'] as int? ?? 0,
        method: j['method'] as String? ?? 'POST',
        path: j['path'] as String,
        fields: Map<String, dynamic>.from(j['fields'] as Map? ?? {}),
        label: j['label'] as String? ?? 'Acción',
        createdAt: DateTime.tryParse(j['created_at'] as String? ?? '') ?? DateTime.now(),
        meta: Map<String, dynamic>.from(j['meta'] as Map? ?? {}),
        fileField: j['file_field'] as String?,
        fileName: j['file_name'] as String?,
        filePath: j['file_path'] as String?,
        fileB64: j['file_b64'] as String?,
        error: j['error'] as String?,
      );

  final String id;
  final int userId;
  final String method;
  final String path;
  final Map<String, dynamic> fields;
  final String label;
  final DateTime createdAt;

  /// Datos para mostrar la acción en el teléfono mientras se envía (no van al servidor):
  /// nombre del material, autor del comentario, ticket de la parada...
  final Map<String, dynamic> meta;
  final String? fileField;
  final String? fileName;

  /// Archivo copiado a los documentos de la app (Android / iOS).
  final String? filePath;

  /// En web no hay sistema de archivos: el archivo va en base64.
  final String? fileB64;

  /// Rechazada por el servidor (validación, permiso...): ya no se reintenta sola.
  String? error;

  bool get failed => error != null;

  /// Trabajo al que pertenece (ticket, instalación, parada o cliente). Si una acción se rechaza, las
  /// siguientes del mismo trabajo esperan: "finalizar" no se envía si la firma no pudo guardarse.
  String get group {
    final m = RegExp(r'^/(?:field/)?(tickets|installations|stops)/(\d+)').firstMatch(path);
    if (m != null) return '${m[1]}:${m[2]}';
    if (fields['client_id'] != null) return 'client:${fields['client_id']}';
    return path;
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'user_id': userId,
        'method': method,
        'path': path,
        'fields': fields,
        'label': label,
        'created_at': createdAt.toIso8601String(),
        'meta': meta,
        'file_field': fileField,
        'file_name': fileName,
        'file_path': filePath,
        'file_b64': fileB64,
        'error': error,
      };
}

/// Resultado de [Outbox.submit]: respuesta del servidor o guardado en la bandeja (sin señal).
class SendResult {
  const SendResult(this.response) : item = null;
  const SendResult.queued(OutboxItem this.item) : response = null;

  final Map<String, dynamic>? response;

  /// Acción guardada en la bandeja (sin señal).
  final OutboxItem? item;

  bool get queued => item != null;

  String? get message => response?['message'] as String?;
}

/// Bandeja de salida persistente (shared_preferences). Guarda las acciones de campo hechas sin señal
/// (llegadas, estados, comentarios, fotos, firmas, materiales, checklist, cobros y visitas) y las envía en
/// orden al recuperarla: al volver a la app, cada minuto y tras cualquier respuesta correcta.
/// Cada acción guardada se refleja en la copia local de los datos (LocalPatch) y lleva la hora en que
/// ocurrió (occurred_at), que el servidor registra en lugar de la hora de envío.
class Outbox extends ChangeNotifier {
  Outbox(this.api);

  static const _prefsKey = 'outbox:v1';
  static const offlineMessage = 'Sin conexión: se enviará al recuperar señal';

  /// Aumenta cada vez que se envían acciones: las pantallas abiertas (Loader) recargan los datos del servidor.
  static final synced = ValueNotifier<int>(0);

  final ApiClient api;
  final List<OutboxItem> _items = [];
  int? userId;
  bool _loaded = false;
  bool _flushing = false;
  DateTime? _lastFlush;

  /// Acciones del usuario actual (las de otro usuario esperan a que vuelva a iniciar sesión).
  List<OutboxItem> get items => _items.where((i) => i.userId == userId).toList();
  List<OutboxItem> get pending => items.where((i) => !i.failed).toList();
  int get pendingCount => pending.length;
  int get failedCount => items.where((i) => i.failed).length;
  bool get flushing => _flushing;

  /// Acción pendiente detenida porque otra del mismo trabajo fue rechazada.
  bool waiting(OutboxItem item) => !item.failed && items.any((f) => f.failed && f.group == item.group);

  /// Acciones aún no recibidas por el servidor (pendientes o rechazadas) a [path].
  List<OutboxItem> unsent(String path) => items.where((i) => i.path == path).toList();

  Future<void> load() async {
    if (_loaded) return;
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_prefsKey);
    if (raw != null) {
      try {
        _items
          ..clear()
          ..addAll((jsonDecode(raw) as List).map((e) => OutboxItem.fromJson(Map<String, dynamic>.from(e as Map))));
      } catch (_) {}
    }
    _loaded = true;
    notifyListeners();
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefsKey, jsonEncode(_items.map((i) => i.toJson()).toList()));
    notifyListeners();
  }

  /// Envía la acción con su clave de idempotencia y su hora; si no hay señal la guarda en la bandeja.
  /// Errores del servidor (validación, permisos) se lanzan como ApiException.
  Future<SendResult> submit({
    required String path,
    required String label,
    String method = 'POST',
    Map<String, dynamic>? data,
    OutboxFile? file,
    String fileField = 'file',
    String? key,
    Map<String, dynamic>? meta,
  }) async {
    final id = key ?? newUuid();
    final fields = Map<String, dynamic>.of(data ?? const {})..removeWhere((_, v) => v == null);
    fields.putIfAbsent('occurred_at', () => DateTime.now().toUtc().toIso8601String());
    try {
      return SendResult(await _send(method, path, fields, id, file == null ? null : {fileField: MultipartFile.fromBytes(file.bytes, filename: file.name)}));
    } on ApiException catch (e) {
      if (!e.offline) rethrow;
    }
    await load();
    final item = OutboxItem(
      id: id,
      userId: userId ?? 0,
      method: method,
      path: path,
      fields: fields,
      label: label,
      createdAt: DateTime.now(),
      meta: meta ?? const {},
      fileField: file == null ? null : fileField,
      fileName: file?.name,
      filePath: file == null || kIsWeb ? null : await _persist(id, file),
      fileB64: file != null && kIsWeb ? base64Encode(file.bytes) : null,
    );
    _items.add(item);
    await _save();
    await LocalPatch.apply(item);
    return SendResult.queued(item);
  }

  /// Envía lo pendiente en orden. Se detiene sin señal o si el servidor falla (se reintenta luego).
  Future<void> flush({bool force = false}) async {
    if (_flushing || userId == null) return;
    // Evita ráfagas: tras cada respuesta correcta se llama, pero como máximo cada 10 s.
    if (!force && _lastFlush != null && DateTime.now().difference(_lastFlush!) < const Duration(seconds: 10)) return;
    await load();
    if (pendingCount == 0) return;
    _flushing = true;
    _lastFlush = DateTime.now();
    notifyListeners();
    var sent = 0;
    try {
      final blocked = {for (final i in items) if (i.failed) i.group};
      for (final item in pending) {
        if (blocked.contains(item.group)) continue;
        try {
          final files = await _files(item);
          await _send(item.method, item.path, item.fields, item.id, files);
          await _remove(item);
          sent++;
        } on ApiException catch (e) {
          final retryLater = e.offline || e.status == null || e.status == 401 || e.status == 408 || e.status == 409 || e.status == 429 || e.status! >= 500;
          if (retryLater) break;
          item.error = e.detail;
          blocked.add(item.group);
          await _save();
        } on io.FileSystemException {
          item.error = 'No se encontró el archivo guardado.';
          blocked.add(item.group);
          await _save();
        }
      }
    } finally {
      _flushing = false;
      notifyListeners();
      if (sent > 0) synced.value++;
    }
  }

  /// Quita una acción rechazada (o que el usuario ya no quiere enviar).
  Future<void> discard(OutboxItem item) => _remove(item);

  /// Vuelve a intentar una acción rechazada (p. ej. tras corregir permisos en el panel).
  Future<void> retry(OutboxItem item) async {
    item.error = null;
    await _save();
    await flush(force: true);
  }

  Future<Map<String, dynamic>> _send(String method, String path, Map<String, dynamic> fields, String key, Map<String, MultipartFile>? files) {
    final headers = {'Idempotency-Key': key};
    if (files != null && files.isNotEmpty) return api.upload(path, fields: fields, files: files, headers: headers);
    return switch (method) {
      'PATCH' => api.patch(path, data: fields, headers: headers),
      'DELETE' => api.delete(path, headers: headers),
      _ => api.post(path, data: fields, headers: headers),
    };
  }

  Future<Map<String, MultipartFile>?> _files(OutboxItem item) async {
    if (item.fileField == null) return null;
    final name = item.fileName ?? 'foto.jpg';
    if (item.fileB64 != null) return {item.fileField!: MultipartFile.fromBytes(base64Decode(item.fileB64!), filename: name)};
    if (item.filePath != null) return {item.fileField!: MultipartFile.fromBytes(await io.File(item.filePath!).readAsBytes(), filename: name)};
    return null;
  }

  Future<String> _persist(String id, OutboxFile file) async {
    final docs = await getApplicationDocumentsDirectory();
    final dir = io.Directory('${docs.path}/outbox');
    await dir.create(recursive: true);
    final ext = file.name.contains('.') ? file.name.substring(file.name.lastIndexOf('.')) : '.jpg';
    final saved = io.File('${dir.path}/$id$ext');
    await saved.writeAsBytes(file.bytes, flush: true);
    return saved.path;
  }

  Future<void> _remove(OutboxItem item) async {
    _items.removeWhere((i) => i.id == item.id);
    if (item.filePath != null && !kIsWeb) {
      try {
        await io.File(item.filePath!).delete();
      } catch (_) {}
    }
    await _save();
  }
}
