/// Configuración de compilación de la app de campo.
///
/// El servidor se puede fijar al compilar:
///   flutter build apk --dart-define=API_URL=https://midominio.com
/// y el técnico también lo puede cambiar en la pantalla de inicio de sesión ("Servidor").
class AppConfig {
  static const appName = 'ID-Hub Campo';

  /// URL base del panel (sin /api/v1).
  static const defaultServer = String.fromEnvironment(
    'API_URL',
    defaultValue: 'https://id-hub.digital',
  );

  static const apiPrefix = '/api/v1';

  /// Color de marca por defecto (se reemplaza por el color del WISP al iniciar sesión).
  static const brandColor = 0xFF1A7FF5;

  static const connectTimeout = Duration(seconds: 15);
  static const receiveTimeout = Duration(seconds: 45);
}
