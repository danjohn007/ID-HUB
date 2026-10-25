import 'package:dio/dio.dart';

import '../config.dart';
import 'cache_store.dart';

/// Error de la API con el mensaje en español que devuelve el servidor.
class ApiException implements Exception {
  ApiException(this.message, {this.status, this.errors = const {}, this.offline = false});

  final String message;
  final int? status;
  final Map<String, List<String>> errors;
  final bool offline;

  /// Primer error de validación (o el mensaje general).
  String get detail => errors.values.expand((e) => e).firstOrNull ?? message;

  @override
  String toString() => detail;
}

/// Respuesta GET que puede venir de la caché local cuando no hay señal.
class Cached<T> {
  Cached(this.data, {this.fromCache = false, this.savedAt});

  final T data;
  final bool fromCache;
  final DateTime? savedAt;
}

/// Cliente HTTP de la API v1 (Sanctum: Authorization: Bearer).
class ApiClient {
  ApiClient({required String server, String? token, this.onUnauthorized}) {
    _dio = Dio(BaseOptions(
      connectTimeout: AppConfig.connectTimeout,
      receiveTimeout: AppConfig.receiveTimeout,
      headers: {'Accept': 'application/json'},
    ));
    configure(server: server, token: token);
  }

  late final Dio _dio;
  void Function()? onUnauthorized;

  /// Se llama tras cada respuesta correcta del servidor (hay señal): reintenta la bandeja de salida.
  void Function()? onOnline;

  /// Aplica a una respuesta recién descargada (y a su copia local) las acciones aún en la bandeja.
  Map<String, dynamic> Function(String cacheKey, Map<String, dynamic> data)? pendingOverlay;

  void configure({required String server, String? token}) {
    _dio.options.baseUrl = normalizeServer(server) + AppConfig.apiPrefix;
    if (token == null) {
      _dio.options.headers.remove('Authorization');
    } else {
      _dio.options.headers['Authorization'] = 'Bearer $token';
    }
  }

  static String normalizeServer(String server) {
    var s = server.trim();
    if (s.isEmpty) return AppConfig.defaultServer;
    if (!s.startsWith('http://') && !s.startsWith('https://')) s = 'https://$s';
    s = s.replaceAll(RegExp(r'/+$'), '');
    if (s.endsWith(AppConfig.apiPrefix)) s = s.substring(0, s.length - AppConfig.apiPrefix.length);
    return s;
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get(path, queryParameters: _clean(query)));

  /// [headers]: p. ej. Idempotency-Key para que un reintento no duplique la acción.
  Future<Map<String, dynamic>> post(String path, {Map<String, dynamic>? data, Map<String, String>? headers}) =>
      _send(() => _dio.post(path, data: _clean(data), options: Options(headers: headers)));

  Future<Map<String, dynamic>> patch(String path, {Map<String, dynamic>? data, Map<String, String>? headers}) =>
      _send(() => _dio.patch(path, data: _clean(data), options: Options(headers: headers)));

  Future<Map<String, dynamic>> delete(String path, {Map<String, String>? headers}) => _send(() => _dio.delete(path, options: Options(headers: headers)));

  /// Envío multipart (fotos, firma, evidencia de cobro).
  Future<Map<String, dynamic>> upload(String path, {required Map<String, dynamic> fields, required Map<String, MultipartFile> files, Map<String, String>? headers}) {
    final form = FormData.fromMap({..._clean(fields)!, ...files});
    return _send(() => _dio.post(path, data: form, options: Options(headers: headers)));
  }

  /// GET con respaldo local: si no hay conexión devuelve la última respuesta guardada.
  /// Las acciones guardadas sin señal ya se reflejaron en esa copia (LocalPatch).
  Future<Cached<Map<String, dynamic>>> getCached(String path, {Map<String, dynamic>? query, required String cacheKey}) async {
    try {
      final fresh = await get(path, query: query);
      final data = pendingOverlay?.call(cacheKey, fresh) ?? fresh;
      await CacheStore.write(cacheKey, data);
      return Cached(data);
    } on ApiException catch (e) {
      final saved = e.offline ? await CacheStore.read(cacheKey) : null;
      if (saved == null) rethrow;
      return Cached(saved.data, fromCache: true, savedAt: saved.savedAt);
    }
  }

  static Future<void> clearCache() => CacheStore.clear();

  Map<String, dynamic>? _clean(Map<String, dynamic>? m) => m == null ? null : (Map.of(m)..removeWhere((_, v) => v == null));

  Future<Map<String, dynamic>> _send(Future<Response<dynamic>> Function() request) async {
    try {
      final res = await request();
      onOnline?.call();
      final body = res.data;
      if (body is Map<String, dynamic>) return body;
      if (body is Map) return Map<String, dynamic>.from(body);
      return {'data': body};
    } on DioException catch (e) {
      throw _toApiException(e);
    }
  }

  ApiException _toApiException(DioException e) {
    final res = e.response;
    if (res == null) {
      final timeout = e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.receiveTimeout;
      return ApiException(
        timeout ? 'El servidor tardó demasiado en responder. Revisa tu señal.' : 'Sin conexión con el servidor. Revisa tu señal o la dirección del servidor.',
        offline: true,
      );
    }
    final body = res.data is Map ? Map<String, dynamic>.from(res.data as Map) : <String, dynamic>{};
    final errors = <String, List<String>>{};
    if (body['errors'] is Map) {
      (body['errors'] as Map).forEach((k, v) => errors['$k'] = (v as List).map((x) => '$x').toList());
    }
    if (res.statusCode == 401) onUnauthorized?.call();
    final fallback = switch (res.statusCode) {
      401 => 'Tu sesión expiró. Inicia sesión de nuevo.',
      403 => 'No tienes permiso para esta acción.',
      404 => 'No se encontró el registro.',
      429 => 'Demasiadas solicitudes. Espera un momento.',
      _ => 'Error del servidor (${res.statusCode}).',
    };
    return ApiException(body['message'] as String? ?? fallback, status: res.statusCode, errors: errors);
  }
}
