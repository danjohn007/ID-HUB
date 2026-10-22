import 'dart:async';
import 'dart:convert';
import 'dart:io' as io;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'photos.dart';
import 'uuid.dart';

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
  final String? fileField;
  final String? fileName;

  /// Foto copiada a los documentos de la app (Android / iOS).
  final String? filePath;

  /// En web no hay sistema de archivos: la foto va en base64.
  final String? fileB64;

  /// Rechazada por el servidor (validación, permiso...): ya no se reintenta sola.
  String? error;

  bool get failed => error != null;

  Map<String, dynamic> toJson() => {
        'id': id,
        'user_id': userId,
        'method': method,
        'path': path,
        'fields': fields,
        'label': label,
        'created_at': createdAt.toIso8601String(),
        'file_field': fileField,
        'file_name': fileName,
        'file_path': filePath,
        'file_b64': fileB64,
        'error': error,
      };
}

/// Resultado de [Outbox.submit]: respuesta del servidor o guardado en la bandeja (sin señal).
class SendResult {
  const SendResult(this.response) : queued = false;
  const SendResult.queued()
      : response = null,
        queued = true;

  final Map<String, dynamic>? response;
  final bool queued;

  String? get message => response?['message'] as String?;
}

/// Bandeja de salida persistente (shared_preferences). Guarda llegadas / salidas, cambios de estado,
/// comentarios, visitas y cobros de cobranza y fotos de evidencia cuando no hay señal, y los envía
/// en orden al recuperarla: al volver a la app, cada minuto y tras cualquier respuesta correcta.
class Outbox extends ChangeNotifier {
  Outbox(this.api);

  static const _prefsKey = 'outbox:v1';
  static const offlineMessage = 'Sin conexión: se enviará al recuperar señal';

  final ApiClient api;
  final List<OutboxItem> _items = [];
  int? userId;
  bool _loaded = false;
  bool _flushing = false;
  DateTime? _lastFlush;

  /// Acciones del usuario actual (las de otro usuario esperan a que vuelva a iniciar sesión).
  List<OutboxItem> get items => _items.where((i) => i.userId == userId).toList();
  int get pendingCount => items.where((i) => !i.failed).length;
  int get failedCount => items.where((i) => i.failed).length;
  bool get flushing => _flushing;

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

  /// Envía la acción con su clave de idempotencia; si no hay señal la guarda en la bandeja.
  /// Errores del servidor (validación, permisos) se lanzan como ApiException.
  Future<SendResult> submit({
    required String path,
    required String label,
    String method = 'POST',
    Map<String, dynamic>? data,
    EvidencePhoto? photo,
    String fileField = 'file',
    String? key,
  }) async {
    final id = key ?? newUuid();
    final fields = Map<String, dynamic>.of(data ?? const {})..removeWhere((_, v) => v == null);
    try {
      return SendResult(await _send(method, path, fields, id, photo == null ? null : {fileField: photo.toMultipart()}));
    } on ApiException catch (e) {
      if (!e.offline) rethrow;
    }
    await load();
    _items.add(OutboxItem(
      id: id,
      userId: userId ?? 0,
      method: method,
      path: path,
      fields: fields,
      label: label,
      createdAt: DateTime.now(),
      fileField: photo == null ? null : fileField,
      fileName: photo?.name,
      filePath: photo == null || kIsWeb ? null : await _persist(id, photo),
      fileB64: photo != null && kIsWeb ? base64Encode(photo.bytes) : null,
    ));
    await _save();
    return const SendResult.queued();
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
    try {
      for (final item in items.where((i) => !i.failed).toList()) {
        try {
          final files = await _files(item);
          await _send(item.method, item.path, item.fields, item.id, files);
          await _remove(item);
        } on ApiException catch (e) {
          final retryLater = e.offline || e.status == null || e.status == 401 || e.status == 408 || e.status == 409 || e.status == 429 || e.status! >= 500;
          if (retryLater) break;
          item.error = e.detail;
          await _save();
        } on io.FileSystemException {
          item.error = 'No se encontró la foto guardada.';
          await _save();
        }
      }
    } finally {
      _flushing = false;
      notifyListeners();
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
    return method == 'PATCH' ? api.patch(path, data: fields, headers: headers) : api.post(path, data: fields, headers: headers);
  }

  Future<Map<String, MultipartFile>?> _files(OutboxItem item) async {
    if (item.fileField == null) return null;
    final name = item.fileName ?? 'foto.jpg';
    if (item.fileB64 != null) return {item.fileField!: MultipartFile.fromBytes(base64Decode(item.fileB64!), filename: name)};
    if (item.filePath != null) return {item.fileField!: MultipartFile.fromBytes(await io.File(item.filePath!).readAsBytes(), filename: name)};
    return null;
  }

  Future<String> _persist(String id, EvidencePhoto photo) async {
    final docs = await getApplicationDocumentsDirectory();
    final dir = io.Directory('${docs.path}/outbox');
    await dir.create(recursive: true);
    final file = io.File('${dir.path}/$id.jpg');
    await file.writeAsBytes(photo.bytes, flush: true);
    return file.path;
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
