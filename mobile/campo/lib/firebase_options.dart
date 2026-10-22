// Marcador: este archivo lo reemplaza `flutterfire configure` con los datos del proyecto
// de Firebase (ver docs/DESPLIEGUE.md → Notificaciones push). Mientras no se configure,
// la app funciona normal y solo las notificaciones push quedan desactivadas.
import 'package:firebase_core/firebase_core.dart' show FirebaseOptions;

class DefaultFirebaseOptions {
  static FirebaseOptions get currentPlatform =>
      throw UnsupportedError('Firebase no está configurado: ejecuta `flutterfire configure` en mobile/campo.');
}
